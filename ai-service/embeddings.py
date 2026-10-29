"""Turning an image into a vector.

Two backends:

  SigLIP    - a real vision model. Understands *what* an object is, so a
              phone photographed on a desk still matches the same phone
              photographed in a hand.

  Fallback  - colour and structure statistics computed with Pillow and
              numpy. No model download, no torch. It reliably matches
              "another photo of the same black bag on the same carpet",
              and reliably does not understand objects.

The fallback is not pretending to be as good. It exists so the system is
demonstrably working the moment you start it, and so the Laravel side can
be developed and tested without a 2.5GB dependency. Swap it by setting
AI_EMBEDDING_BACKEND=siglip once torch is installed.
"""

from __future__ import annotations

import logging
from io import BytesIO

import numpy as np
from PIL import Image

import config

logger = logging.getLogger(__name__)

# Dimensions of the fallback descriptor. Fixed, because every vector in
# one index must have the same length.
FALLBACK_DIMENSIONS = 512


def _load_image(data: bytes) -> Image.Image:
    """Decode bytes into a normalised RGB image.

    Pillow is told the pixel ceiling explicitly: a 200KB PNG can decode
    to gigabytes, and this service accepts uploads.
    """
    Image.MAX_IMAGE_PIXELS = config.MAX_PIXELS

    image = Image.open(BytesIO(data))
    image.draft("RGB", image.size)  # cheap downscale hint for JPEGs
    return image.convert("RGB")


def _normalise(vector: np.ndarray) -> np.ndarray:
    """Scale to unit length.

    FAISS's inner-product index returns cosine similarity only if every
    vector is normalised, so this is what makes the scores comparable and
    bounded in 0..1.
    """
    norm = np.linalg.norm(vector)
    if norm == 0:
        return vector.astype("float32")
    return (vector / norm).astype("float32")


class FallbackEmbedder:
    """Colour + structure descriptor. Pillow and numpy only."""

    name = "colour-structure"
    version = "1"
    dimensions = FALLBACK_DIMENSIONS

    def embed(self, data: bytes) -> np.ndarray:
        image = _load_image(data)

        # A small fixed size makes the descriptor independent of the
        # original resolution - two photos of one object taken on
        # different phones should land close together.
        thumb = image.resize((64, 64), Image.Resampling.BILINEAR)
        pixels = np.asarray(thumb, dtype="float32") / 255.0

        parts: list[np.ndarray] = []

        # 1. Colour histogram, 8 bins per channel. Captures "this is a
        #    mostly-black object with a red stripe".
        for channel in range(3):
            hist, _ = np.histogram(pixels[:, :, channel], bins=8, range=(0.0, 1.0))
            parts.append(hist.astype("float32"))

        # 2. A coarse 8x8 grayscale thumbnail. Captures rough layout, so
        #    a laptop (wide, dark block) differs from a key ring.
        gray = pixels.mean(axis=2)
        blocks = gray.reshape(8, 8, 8, 8).mean(axis=(1, 3))
        parts.append(blocks.flatten())

        # 3. Edge density per block - a proxy for texture and detail.
        gy, gx = np.gradient(gray)
        edges = np.sqrt(gx**2 + gy**2)
        edge_blocks = edges.reshape(8, 8, 8, 8).mean(axis=(1, 3))
        parts.append(edge_blocks.flatten())

        # 4. Per-block mean colour, so spatial colour arrangement counts.
        for channel in range(3):
            c = pixels[:, :, channel].reshape(8, 8, 8, 8).mean(axis=(1, 3))
            parts.append(c.flatten())

        vector = np.concatenate(parts)

        # Pad to the fixed width so the index geometry never changes.
        if vector.size < self.dimensions:
            vector = np.pad(vector, (0, self.dimensions - vector.size))

        return _normalise(vector[: self.dimensions])


class SigLipEmbedder:
    """Google SigLIP via transformers. Loaded lazily on first use."""

    name = "siglip"

    def __init__(self, model_id: str):
        import torch  # noqa: PLC0415 - imported here so the module stays optional
        from transformers import AutoImageProcessor, AutoModel  # noqa: PLC0415

        self._torch = torch
        self.version = model_id

        logger.info("Loading SigLIP (%s) - first run downloads weights.", model_id)

        # AutoImageProcessor, not AutoProcessor.
        #
        # AutoProcessor builds the text tokenizer as well, and
        # SiglipTokenizer requires sentencepiece - a compiled package that
        # is extra weight at best and unavailable on new Python versions
        # at worst. This service only ever embeds images, so the text side
        # is never used.
        self._processor = AutoImageProcessor.from_pretrained(model_id)

        self._model = AutoModel.from_pretrained(model_id)
        self._model.eval()

        self.dimensions = int(self._model.config.vision_config.hidden_size)

    def embed(self, data: bytes) -> np.ndarray:
        image = _load_image(data)
        inputs = self._processor(images=image, return_tensors="pt")

        with self._torch.no_grad():
            features = self._model.get_image_features(**inputs)

        vector = self._to_pooled_vector(features)

        return _normalise(vector)

    def _to_pooled_vector(self, features):
        """Reduce whatever get_image_features returned to one vector.

        Its return type is not stable across transformers versions: it may
        be a bare tensor, or a ModelOutput wrapping the whole vision
        output. Indexing [0] blindly gets last_hidden_state - the
        per-patch states, 196 x 768 = 150528 values - which is not an
        embedding and does not match the dimensions this class advertises
        or the FAISS index built from them.

        Both wrong shapes still normalise to 1.0 and still rank images
        plausibly, so this fails quietly rather than loudly. Hence the
        explicit cascade, and the assertion below.
        """
        # The attention-pooled head output: SigLIP's actual image embedding.
        if getattr(features, "pooler_output", None) is not None:
            tensor = features.pooler_output
        elif getattr(features, "image_embeds", None) is not None:
            tensor = features.image_embeds
        elif hasattr(features, "detach"):
            tensor = features
        elif getattr(features, "last_hidden_state", None) is not None:
            # Mean-pool across patches as a last resort.
            tensor = features.last_hidden_state.mean(dim=1)
        else:
            tensor = features[0]

        # Drop the batch dimension; mean-pool if patches survived.
        if tensor.ndim == 3:
            tensor = tensor.mean(dim=1)

        vector = tensor.detach().cpu().numpy().reshape(-1)

        if vector.size != self.dimensions:
            raise RuntimeError(
                f"SigLIP produced a {vector.size}-value vector but this model "
                f"reports {self.dimensions} dimensions. Refusing to index a "
                f"vector that does not match the index geometry."
            )

        return vector


def build_embedder():
    """Pick a backend according to config, degrading rather than failing."""
    choice = config.EMBEDDING_BACKEND.lower()

    if choice == "fallback":
        logger.info("Embedding backend: fallback (forced by config).")
        return FallbackEmbedder()

    try:
        embedder = SigLipEmbedder(config.SIGLIP_MODEL)
        logger.info("Embedding backend: SigLIP (%d dims).", embedder.dimensions)
        return embedder
    except Exception as exc:  # noqa: BLE001 - any import/download failure
        if choice == "siglip":
            # Explicitly demanded, so refuse to quietly substitute
            # something weaker.
            raise

        logger.warning(
            "SigLIP unavailable (%s). Falling back to the colour-structure "
            "descriptor - image search will work, but it compares appearance "
            "rather than understanding objects. Install torch and "
            "transformers for better results.",
            exc,
        )
        return FallbackEmbedder()
