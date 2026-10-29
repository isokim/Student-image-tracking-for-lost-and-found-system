"""Describing a photo in words, via Ollama.

Entirely optional. If Ollama is not running, every function here returns
an empty result and Laravel's AnalyzeItemImage job records nothing -
which is the correct outcome, because an empty analysis is honest and an
invented one would put guesses into item_ai_analysis that no model made.
"""

from __future__ import annotations

import base64
import json
import logging

import httpx

import config

logger = logging.getLogger(__name__)

# Categories are constrained to the slugs the Laravel database seeds, so
# a free-text answer like "mobile telephone" cannot end up in a column
# the rest of the system expects to match a known category.
KNOWN_CATEGORIES = [
    "electronics",
    "documents",
    "clothing",
    "bags",
    "accessories",
    "keys",
    "books",
    "other",
]

PROMPT = f"""You are helping a university lost-and-found office catalogue an item.

Look at the image and reply with ONLY a JSON object, no other text:

{{
  "category": one of {KNOWN_CATEGORIES},
  "colour": the single most dominant colour, lowercase,
  "brand": the visible brand name, or null if none is legible,
  "features": up to 5 short distinguishing details you can actually see,
  "description": one factual sentence describing the object
}}

Describe only what is visible. Do not guess a brand that is not legible.
Do not describe people. If the image does not show a physical object,
use "other" for the category."""


def _parse_response(raw: str) -> dict:
    """Pull the JSON object out of a model's reply.

    Vision models routinely wrap JSON in prose or a markdown fence, so
    the object is extracted by bracket matching rather than trusting the
    whole reply to parse.
    """
    start = raw.find("{")
    end = raw.rfind("}")

    if start == -1 or end == -1 or end <= start:
        return {}

    try:
        return json.loads(raw[start : end + 1])
    except json.JSONDecodeError:
        return {}


def analyze(image_bytes: bytes) -> dict:
    """Ask the vision model what the photo shows.

    Returns {} on any failure. The caller treats that as "not analysed".
    """
    payload = {
        "model": config.OLLAMA_MODEL,
        "prompt": PROMPT,
        "images": [base64.b64encode(image_bytes).decode("ascii")],
        "stream": False,
        # Low temperature: this is a cataloguing task, and creative
        # variation in a "what colour is this bag" answer is pure noise.
        "options": {"temperature": 0.1},
    }

    try:
        response = httpx.post(
            f"{config.OLLAMA_URL}/api/generate",
            json=payload,
            timeout=config.OLLAMA_TIMEOUT,
        )
        response.raise_for_status()
    except httpx.HTTPError as exc:
        logger.info("Ollama unavailable (%s) - skipping image analysis.", exc)
        return {}

    parsed = _parse_response(response.json().get("response", ""))

    if not parsed:
        logger.info("Ollama returned no usable JSON.")
        return {}

    return _sanitise(parsed)


def _sanitise(parsed: dict) -> dict:
    """Constrain the model's output to values the rest of the system expects."""
    category = str(parsed.get("category") or "").strip().lower()
    if category not in KNOWN_CATEGORIES:
        # Better to say nothing than to introduce a category Laravel has
        # no row for.
        category = None

    features = parsed.get("features")
    if not isinstance(features, list):
        features = []

    def clean(value, limit: int):
        if value is None:
            return None
        text = str(value).strip()
        return text[:limit] if text and text.lower() not in {"null", "none", "n/a"} else None

    return {
        "category": category,
        "colour": clean(parsed.get("colour"), 50),
        "brand": clean(parsed.get("brand"), 100),
        "features": [str(f).strip()[:100] for f in features[:5] if str(f).strip()],
        "description": clean(parsed.get("description"), 500),
        "model_name": config.OLLAMA_MODEL,
        "model_version": config.OLLAMA_MODEL,
    }


# Cached so /health stays fast.
#
# This used to probe Ollama on every call, and when Ollama is not running
# that probe blocks for the full timeout. /health is what Laravel polls to
# decide whether the AI service is up, so a 2-second health check made
# Laravel's own 2-second client timeout fail - the service reported itself
# DOWN purely because a *different*, optional service was down.
_availability_cache: dict[str, float | bool] = {"checked_at": 0.0, "available": False}
_CACHE_SECONDS = 30.0


def is_available(force: bool = False) -> bool:
    import time  # noqa: PLC0415

    now = time.monotonic()

    if not force and now - float(_availability_cache["checked_at"]) < _CACHE_SECONDS:
        return bool(_availability_cache["available"])

    try:
        # 2 seconds, not 0.5.
        #
        # Ollama's /api/tags takes 0.5-0.9s on a normal Windows machine
        # even when it is healthy, so a 0.5s probe reported "down" for a
        # service that was running perfectly. The 30-second cache above is
        # what keeps /health fast - the probe itself does not need to be
        # brutally short, it needs to be correct.
        response = httpx.get(f"{config.OLLAMA_URL}/api/tags", timeout=2.0)
        available = response.status_code == 200
    except httpx.HTTPError:
        available = False

    _availability_cache["checked_at"] = now
    _availability_cache["available"] = available

    return available
