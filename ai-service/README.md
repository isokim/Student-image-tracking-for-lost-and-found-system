# AI service

Image analysis, embeddings and vector similarity for the Lost & Found
system. Python, FastAPI, FAISS, and optionally Ollama.

Runs on `http://127.0.0.1:8001`. Only Laravel talks to it — it is never
exposed to the browser.

---

## What it does, and what it deliberately does not

It answers one question: **which of these images look alike?**

It does **not** decide who may see what. It does not know which items are
verified, who owns them, or who is asking. Laravel treats the item ids it
returns as a *shortlist to re-check* against its own visibility rules,
never as a result set — so even a compromised AI service cannot surface
an item a student is not allowed to see. There is a test for exactly that
(`AiServiceTest::test_python_is_never_given_authority_over_who_sees_what`).

That separation is also why the service is relocatable. Moving it to a
cloud host is one line in `backend/.env`:

```
AI_SERVICE_URL=https://your-host.example.com
```

No Laravel code changes, no migration, and the React app never finds out.

---

## Running it

```bash
cd ai-service
python -m venv .venv
.venv/Scripts/python.exe -m pip install -r requirements.txt   # Windows
# source .venv/bin/activate && pip install -r requirements.txt  # macOS/Linux

.venv/Scripts/python.exe -m uvicorn main:app --host 127.0.0.1 --port 8001
```

Check it:

```bash
curl http://127.0.0.1:8001/health
```

Settings come from `ai-service/.env` (copy `.env.example`). The one that
matters is `AI_SERVICE_TOKEN` — it must match `backend/.env`, or every
request is rejected with a 401. Real environment variables still override
the file, so `AI_SERVICE_TOKEN=x uvicorn ...` works too.

**The Laravel app runs fine without this service.** Set
`AI_SERVICE_ENABLED=false` in `backend/.env` and everything except image
similarity keeps working — reporting, verification, claims, notifications,
keyword search, and text/category/location/time matching.

---

## The three tiers

This is the part worth understanding before judging the results.

| Tier | Needs | What you get |
|---|---|---|
| **Fallback** (default) | nothing extra | Real, working image similarity based on colour and structure |
| **SigLIP** | `requirements-siglip.txt` (~2.5GB) | Understands *what an object is* |
| **Ollama** | Ollama + a vision model | Written descriptions and detected attributes |

### Why there is a fallback at all

Requiring a 2.5GB download before anything works would mean most people
never see image search function. The default descriptor uses Pillow and
numpy only, and it genuinely works: it reliably matches another photo of
the same bag on the same carpet.

It is also genuinely limited, and worth being honest about: it compares
**appearance**, not objects. A black phone on a dark desk and a black
wallet on a dark desk look similar to it. It will not recognise that two
photos show the same laptop from different angles.

### Upgrading to SigLIP

```bash
pip install -r requirements.txt -r requirements-siglip.txt
```

Then restart. `AI_EMBEDDING_BACKEND=auto` picks SigLIP automatically once
torch is present; set it to `siglip` to make the service refuse to start
rather than quietly falling back, or `fallback` to force the light one.

Note there is no `sentencepiece` dependency, and that is deliberate: this
service only embeds images, so it loads `AutoImageProcessor` rather than
the full `AutoProcessor`, which would drag in a text tokenizer that needs
a compiled package.

**Delete `ai-service/storage/` when you switch.** Vectors produced by two
different models are not comparable — mixing them silently produces
meaningless similarity scores.

### Adding Ollama (optional)

```bash
ollama pull llava:7b
ollama serve
```

Only `/analyze` uses it. With Ollama down, `/analyze` returns empty
fields and Laravel records nothing, which is the correct outcome — an
invented analysis would put guesses into `item_ai_analysis` that no model
ever made.

---

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/health` | Liveness. Unauthenticated, so Laravel can poll it. |
| `POST` | `/analyze` | Describe a photo. Degrades to empty without Ollama. |
| `POST` | `/embed` | Embed and index a photo, or embed a search query transiently. |
| `POST` | `/similar` | Nearest neighbours of an embedded image. |
| `DELETE` | `/embeddings/{item_type}/{item_id}` | Drop an item's vector. |

Interactive docs while it is running: <http://127.0.0.1:8001/docs>

### A note on `transient`

`POST /embed` with `transient=true` embeds a photo **without** indexing
it. That is what an image search sends. Without it, every search query
would become a findable "item" and students would start matching against
each other's searches.

---

## Keeping the index in step with the database

MySQL and the vector index are separate stores, and nothing keeps them in
sync automatically. After `php artisan migrate:fresh` the index still
holds vectors for item ids that no longer exist.

To rebuild:

```bash
rm -rf ai-service/storage          # drop every vector
cd backend && php artisan ai:reindex --fresh
php artisan queue:work             # process the queued jobs
```

---

## FAISS

`faiss-cpu` is in `requirements.txt` and is used when present. If it is
missing, `index.py` falls back to a numpy brute-force search with the
same interface.

For a campus lost-and-found — hundreds to low thousands of items — the
two are indistinguishable in speed. FAISS matters at a scale this system
will not reach; it is here because it is the right tool if it ever does.
