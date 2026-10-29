"""FastAPI service for the Student Lost & Found system.

Laravel calls four endpoints:

    GET  /health    is the service up?
    POST /analyze   what is in this photo?
    POST /embed     turn this photo into an indexed vector
    POST /similar   what looks like the thing behind this reference?

WHAT THIS SERVICE DOES NOT DO, deliberately: it makes no authorisation
decisions. It does not know which items are verified, who owns what, or
who is asking. It answers "these images look alike" and nothing else.
Laravel treats the ids coming back as a shortlist to re-check against its
own visibility rules, never as a result set.

That separation is also what makes the service relocatable: moving it to
a cloud host is a URL change in backend/.env, because nothing here holds
state Laravel depends on beyond the vector index it owns outright.
"""

from __future__ import annotations

import logging
import secrets
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile
from pydantic import BaseModel, Field

import config
import vision
from embeddings import build_embedder
from index import VectorIndex

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-8s %(name)s: %(message)s",
)
logger = logging.getLogger("ai-service")

state: dict = {}


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Load the model and index once, at startup.

    Doing this per-request would mean re-reading the model for every
    photo - the difference between a slow endpoint and an unusable one.
    """
    embedder = build_embedder()

    state["embedder"] = embedder
    state["index"] = VectorIndex(config.INDEX_DIR, embedder.dimensions)

    logger.info(
        "Ready. embedder=%s dims=%d index=%s ollama=%s",
        embedder.name,
        embedder.dimensions,
        state["index"].stats()["backend"],
        "up" if vision.is_available() else "down",
    )

    yield

    logger.info("Shutting down.")


app = FastAPI(
    title="Lost & Found AI service",
    description="Image analysis, embeddings and vector similarity. No authorisation.",
    version="1.0.0",
    lifespan=lifespan,
)


# ---------------------------------------------------------------------
# Authentication
# ---------------------------------------------------------------------

def require_token(authorization: str | None = Header(default=None)) -> None:
    """Checks the shared secret Laravel sends as a bearer token.

    compare_digest rather than == so the comparison does not leak the
    token's length or content through timing.
    """
    if not config.API_TOKEN:
        return  # explicitly disabled

    expected = f"Bearer {config.API_TOKEN}"

    if not authorization or not secrets.compare_digest(authorization, expected):
        raise HTTPException(status_code=401, detail="Invalid or missing API token.")


# ---------------------------------------------------------------------
# Upload validation
# ---------------------------------------------------------------------

async def read_image(upload: UploadFile) -> bytes:
    """Reads and validates an uploaded image.

    Laravel validates uploads too. This is repeated rather than assumed,
    because a service that is only safe when its caller behaves is not
    safe - and this one listens on a port.
    """
    if upload.content_type not in config.ALLOWED_MIME_TYPES:
        raise HTTPException(
            status_code=415,
            detail=f"Unsupported type {upload.content_type}. Send JPEG, PNG or WebP.",
        )

    data = await upload.read()

    if not data:
        raise HTTPException(status_code=400, detail="The uploaded file was empty.")

    if len(data) > config.MAX_IMAGE_BYTES:
        raise HTTPException(status_code=413, detail="That image is too large.")

    return data


# ---------------------------------------------------------------------
# Schemas
# ---------------------------------------------------------------------

class SimilarRequest(BaseModel):
    reference: str
    target_item_type: str = Field(pattern="^(lost_item|found_item)$")
    limit: int = Field(default=20, ge=1, le=200)


# ---------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------

@app.get("/health")
def health() -> dict:
    """Unauthenticated on purpose - Laravel polls this to decide whether
    to offer image search, and a 401 would read as "down"."""
    index = state.get("index")
    embedder = state.get("embedder")

    return {
        "status": "ok",
        "embedder": getattr(embedder, "name", "loading"),
        "dimensions": getattr(embedder, "dimensions", 0),
        "index": index.stats() if index else {},
        "ollama": vision.is_available(),
    }


@app.post("/analyze", dependencies=[Depends(require_token)])
async def analyze(image: UploadFile = File(...)) -> dict:
    """Describe the photo. Returns empty fields when Ollama is not running.

    Never raises for a missing model: the analysis is advisory, and
    failing the request would make a student's report fail to save.
    """
    data = await read_image(image)
    result = vision.analyze(data)

    if not result:
        return {
            "category": None,
            "colour": None,
            "brand": None,
            "features": [],
            "description": None,
            "model_name": "unavailable",
            "model_version": "unavailable",
        }

    return result


@app.post("/embed", dependencies=[Depends(require_token)])
async def embed(
    image: UploadFile = File(...),
    item_type: str | None = Form(default=None),
    item_id: str | None = Form(default=None),
    transient: str | None = Form(default=None),
) -> dict:
    """Embed a photo.

    With item_type/item_id the vector is indexed and can be matched
    against later. With transient=true it is held in memory only - a
    search query is not an item, and indexing it would make the student's
    own search a findable result.
    """
    data = await read_image(image)
    embedder = state["embedder"]
    index = state["index"]

    try:
        vector = embedder.embed(data)
    except Exception as exc:  # noqa: BLE001
        logger.warning("Could not embed the image: %s", exc)
        raise HTTPException(status_code=422, detail="That image could not be processed.") from exc

    is_transient = str(transient).lower() in {"1", "true", "yes"}

    if is_transient or not item_type or not item_id:
        reference = index.add_transient(vector)
    else:
        if item_type not in {"lost_item", "found_item"}:
            raise HTTPException(status_code=422, detail="Unknown item_type.")
        reference = index.add(vector, item_type, item_id)

    return {
        "reference": reference,
        "model_name": embedder.name,
        "model_version": embedder.version,
        "dimensions": embedder.dimensions,
    }


@app.post("/similar", dependencies=[Depends(require_token)])
def similar(payload: SimilarRequest) -> dict:
    """Nearest neighbours of an already-embedded image.

    Returns raw visual similarity only. Laravel combines it with
    category, colour, location and time - none of which this service
    knows about - and decides what the user may actually see.
    """
    results = state["index"].search(
        payload.reference,
        payload.target_item_type,
        payload.limit,
    )

    return {"results": results}


@app.delete("/embeddings/{item_type}/{item_id}", dependencies=[Depends(require_token)])
def remove(item_type: str, item_id: str) -> dict:
    """Drop an item's vector, for when a report is deleted."""
    return {"removed": state["index"].remove(item_type, item_id)}
