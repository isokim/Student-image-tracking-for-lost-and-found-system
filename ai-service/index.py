"""The vector index.

FAISS owns the vectors. Laravel stores only the reference string this
module hands back, which is what lets this whole service be replaced or
moved without a database migration.

A numpy brute-force index stands in when faiss is not installed. For a
campus lost-and-found - hundreds to low thousands of items - the two are
indistinguishable in speed; FAISS matters at a scale this system will not
reach. Same interface either way, so nothing above here has to care.
"""

from __future__ import annotations

import json
import logging
import threading
import uuid
from pathlib import Path

import numpy as np

logger = logging.getLogger(__name__)

try:
    import faiss  # type: ignore

    FAISS_AVAILABLE = True
except ImportError:  # pragma: no cover - depends on the environment
    FAISS_AVAILABLE = False


class VectorIndex:
    """Stores vectors and answers nearest-neighbour queries.

    A `reference` is the id Laravel persists in item_embeddings. It maps
    to a stored vector plus the item it belongs to, so /similar can be
    asked "what looks like the thing behind this reference?" without the
    caller ever handling a vector.
    """

    def __init__(self, directory: Path, dimensions: int):
        self.dir = Path(directory)
        self.dir.mkdir(parents=True, exist_ok=True)
        self.dimensions = dimensions

        # Every mutation is guarded: uvicorn serves requests concurrently
        # and a half-written index is much worse than a slow one.
        self._lock = threading.Lock()

        self._vectors: dict[str, np.ndarray] = {}
        self._meta: dict[str, dict] = {}

        # Query images are embedded but must never pollute the index, or
        # searching for a laptop would make your search itself a
        # findable "item". Held in memory only.
        self._transient: dict[str, np.ndarray] = {}

        self._load()

    # -----------------------------------------------------------------
    # Persistence
    # -----------------------------------------------------------------

    @property
    def _vectors_path(self) -> Path:
        return self.dir / f"vectors-{self.dimensions}.npz"

    @property
    def _meta_path(self) -> Path:
        return self.dir / f"meta-{self.dimensions}.json"

    def _load(self) -> None:
        if not self._meta_path.exists() or not self._vectors_path.exists():
            logger.info("Starting with an empty index (%d dims).", self.dimensions)
            return

        try:
            self._meta = json.loads(self._meta_path.read_text(encoding="utf-8"))
            archive = np.load(self._vectors_path)
            self._vectors = {key: archive[key] for key in archive.files}
            logger.info("Loaded %d vectors from disk.", len(self._vectors))
        except Exception as exc:  # noqa: BLE001
            # A corrupt index is recoverable - Laravel can re-embed. A
            # service that will not start is not.
            logger.warning("Could not read the index (%s). Starting empty.", exc)
            self._vectors, self._meta = {}, {}

    def _persist(self) -> None:
        """Written atomically, so a crash mid-write cannot corrupt it."""
        # Re-created on every write, not just at startup. The directory
        # can disappear underneath a running service - deleting
        # ai-service/storage/ is the documented way to reset the index -
        # and without this every subsequent write raises FileNotFoundError
        # and the service stops embedding anything until it is restarted.
        self.dir.mkdir(parents=True, exist_ok=True)

        tmp_vectors = self._vectors_path.with_suffix(".tmp.npz")
        tmp_meta = self._meta_path.with_suffix(".tmp.json")

        np.savez(tmp_vectors, **self._vectors)
        tmp_meta.write_text(json.dumps(self._meta), encoding="utf-8")

        tmp_vectors.replace(self._vectors_path)
        tmp_meta.replace(self._meta_path)

    # -----------------------------------------------------------------
    # Writing
    # -----------------------------------------------------------------

    def add(self, vector: np.ndarray, item_type: str, item_id: str) -> str:
        """Index a vector for a real item. Returns its reference."""
        with self._lock:
            # Re-embedding the same item replaces its vector rather than
            # adding a second one, so an edited report does not match
            # itself twice.
            existing = self._reference_for(item_type, item_id)
            reference = existing or f"vec_{uuid.uuid4().hex}"

            self._vectors[reference] = vector.astype("float32")
            self._meta[reference] = {"item_type": item_type, "item_id": str(item_id)}
            self._persist()

        return reference

    def add_transient(self, vector: np.ndarray) -> str:
        """Hold a search query's vector without indexing it."""
        reference = f"tmp_{uuid.uuid4().hex}"

        with self._lock:
            # Bounded so a burst of searches cannot grow without limit.
            if len(self._transient) > 500:
                self._transient.clear()

            self._transient[reference] = vector.astype("float32")

        return reference

    def remove(self, item_type: str, item_id: str) -> bool:
        with self._lock:
            reference = self._reference_for(item_type, item_id)
            if reference is None:
                return False

            self._vectors.pop(reference, None)
            self._meta.pop(reference, None)
            self._persist()

        return True

    def _reference_for(self, item_type: str, item_id: str) -> str | None:
        for reference, meta in self._meta.items():
            if meta["item_type"] == item_type and meta["item_id"] == str(item_id):
                return reference
        return None

    # -----------------------------------------------------------------
    # Searching
    # -----------------------------------------------------------------

    def search(self, reference: str, target_item_type: str, limit: int) -> list[dict]:
        """Nearest neighbours of a stored (or transient) vector.

        Filtered to one item type, because a lost report is only ever
        matched against found items and vice versa.
        """
        # Checked explicitly rather than with `a or b`: numpy raises
        # "truth value of an array is ambiguous" when an array is used in
        # a boolean context.
        query = self._transient.get(reference)

        if query is None:
            query = self._vectors.get(reference)

        if query is None:
            logger.info("Unknown embedding reference: %s", reference)
            return []

        with self._lock:
            candidates = [
                (ref, vec)
                for ref, vec in self._vectors.items()
                # Never return the query's own item as its own match.
                if ref != reference and self._meta[ref]["item_type"] == target_item_type
            ]

        if not candidates:
            return []

        matrix = np.vstack([vec for _, vec in candidates])
        scores = self._similarities(query, matrix)

        ranked = sorted(
            zip((ref for ref, _ in candidates), scores),
            key=lambda pair: pair[1],
            reverse=True,
        )[:limit]

        return [
            {
                "item_type": self._meta[ref]["item_type"],
                "item_id": int(self._meta[ref]["item_id"]),
                # Clamped: Laravel multiplies this into a weighted score,
                # so a value outside 0..1 would skew every match.
                "score": float(max(0.0, min(1.0, score))),
            }
            for ref, score in ranked
        ]

    def _similarities(self, query: np.ndarray, matrix: np.ndarray) -> np.ndarray:
        """Cosine similarity, via FAISS when it is installed.

        Both vectors are already unit length, so the inner product IS the
        cosine - which is why IndexFlatIP is the right FAISS index here
        and why the numpy path is a plain dot product.
        """
        if FAISS_AVAILABLE:
            index = faiss.IndexFlatIP(self.dimensions)
            index.add(matrix)
            scores, positions = index.search(query.reshape(1, -1), len(matrix))

            ordered = np.zeros(len(matrix), dtype="float32")
            for score, position in zip(scores[0], positions[0]):
                if position >= 0:
                    ordered[position] = score
            return ordered

        return matrix @ query

    # -----------------------------------------------------------------

    def stats(self) -> dict:
        return {
            "vectors": len(self._vectors),
            "dimensions": self.dimensions,
            "backend": "faiss" if FAISS_AVAILABLE else "numpy",
        }
