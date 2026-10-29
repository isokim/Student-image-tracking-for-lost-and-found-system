"""Settings for the AI service.

Everything is read from the environment so nothing about this service is
baked into Laravel. Laravel knows one thing: the URL.
"""

import os
from pathlib import Path

BASE_DIR = Path(__file__).parent

# Load ai-service/.env if it exists, so settings can live in a file the
# way they do for Laravel. Without this the shipped .env.example would be
# decorative - os.getenv alone never reads a file, so copying it would
# silently change nothing.
#
# Real environment variables still win, so `AI_SERVICE_TOKEN=x uvicorn ...`
# overrides the file rather than being ignored by it.
try:
    from dotenv import load_dotenv

    load_dotenv(BASE_DIR / ".env", override=False)
except ImportError:  # pragma: no cover - dotenv is a convenience, not a need
    pass

# ---------------------------------------------------------------------
# Authentication
# ---------------------------------------------------------------------
# Shared secret, matched against AI_SERVICE_TOKEN in backend/.env. This
# service is never exposed to the browser, but without a token any other
# process on the machine could push junk into the vector index or read
# what has been handed in.
API_TOKEN = os.getenv("AI_SERVICE_TOKEN", "local-dev-shared-secret")

# ---------------------------------------------------------------------
# Embedding backend
# ---------------------------------------------------------------------
# "auto"     - use SigLIP if torch + transformers are installed, else fall
#              back to the dependency-free descriptor
# "siglip"   - require SigLIP, fail loudly if unavailable
# "fallback" - always use the lightweight descriptor
#
# The fallback exists so this service is useful the moment it starts.
# Requiring a 2.5GB torch download before anything works would mean most
# people never see image search at all.
EMBEDDING_BACKEND = os.getenv("AI_EMBEDDING_BACKEND", "auto")

SIGLIP_MODEL = os.getenv("AI_SIGLIP_MODEL", "google/siglip-base-patch16-224")

# ---------------------------------------------------------------------
# Vector index
# ---------------------------------------------------------------------
INDEX_DIR = Path(os.getenv("AI_INDEX_DIR", BASE_DIR / "storage"))

# ---------------------------------------------------------------------
# Ollama (optional)
# ---------------------------------------------------------------------
# Used by /analyze to describe a photo in words. If Ollama is not
# running, /analyze returns an empty result and Laravel carries on - the
# analysis is advisory, never required.
OLLAMA_URL = os.getenv("OLLAMA_URL", "http://127.0.0.1:11434")
OLLAMA_MODEL = os.getenv("OLLAMA_VISION_MODEL", "llava:7b")
# Generous, because it has to be. A 7B vision model on CPU routinely
# takes two to four minutes per image - measured at 135s on this machine,
# which silently blew the old 120s limit and made every analysis return
# "unavailable" while looking like a success.
#
# Nothing waits on this interactively: it runs in a queued job.
OLLAMA_TIMEOUT = int(os.getenv("OLLAMA_TIMEOUT", "300"))

# ---------------------------------------------------------------------
# Limits
# ---------------------------------------------------------------------
# Mirrors the 8MB ceiling the Laravel form requests enforce. Repeated
# here because this service must not assume it is only ever called by a
# correctly-behaving client.
MAX_IMAGE_BYTES = int(os.getenv("AI_MAX_IMAGE_BYTES", str(8 * 1024 * 1024)))

ALLOWED_MIME_TYPES = {"image/jpeg", "image/png", "image/webp"}

# Guards against decompression bombs: a small file can still decode to a
# 40000x40000 image and exhaust memory.
MAX_PIXELS = int(os.getenv("AI_MAX_PIXELS", str(50_000_000)))
