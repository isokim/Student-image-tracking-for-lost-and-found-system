# Student Lost & Found — Laravel API

Backend for the React app in `../lost-and-found`. Laravel 12, PHP 8.3, MySQL.

Everything runs locally. There is no cloud service, no Docker, and no paid
API anywhere in this project.

---

## What talks to what

```
React (Vite :5173)
      |  session cookie, JSON
      v
Laravel API (:8002) ------> MySQL
      |         |
      |         +---------> storage/app/private/items   (photos, never public)
      |
      +-------> Reverb (:8085)     real-time notifications
      |
      +-------> FastAPI (:8001)    image analysis, embeddings, FAISS
                     |
                     +-----> Ollama / SigLIP
```

**Laravel owns every decision about who may see or do what.** The Python
service answers one narrow question — "which images look alike?" — and is
never trusted with authorisation. Its answers are treated as a shortlist to
re-check, not a result set.

---

## Getting it running

You need PHP 8.2+, Composer, and MySQL (XAMPP is fine).

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Create the databases:

```sql
CREATE DATABASE lost_and_found      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE lost_and_found_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then:

```bash
php artisan migrate --seed
php artisan serve          # http://localhost:8002
```

**On ports.** This app runs on **8002**, not the Laravel default 8000, and
Reverb on **8085**, not 8080 - both defaults were already taken on the
machine this was built on. `SERVER_PORT` and `REVERB_SERVER_PORT` in
`.env` mean you do not have to pass flags. If you move them, change
`VITE_API_URL` / `VITE_REVERB_PORT` in the frontend `.env` and
`SANCTUM_STATEFUL_DOMAINS` here to match, or cookie auth silently stops
working.

Check what is already listening before you start:

```powershell
netstat -ano | findstr LISTENING | findstr ":8002 :8085 :8001 :5173"
```

Point the frontend at it — in `../lost-and-found/.env`:

```
VITE_API_URL=http://localhost:8002/api
```

The frontend has no mock mode — it talks to this API or it shows an
error. Start the backend before `npm run dev`.

### Seeded accounts

| Email | Password | Role |
|---|---|---|
| `admin@lostandfound.test` | `password` | admin |
| `staff@lostandfound.test` | `password` | staff |

Students register themselves through the app. These two exist because
staff and admin accounts can only be created through a controlled process —
never public registration.

**Local development accounts only. Change them before this goes anywhere real.**

### The background processes

Two optional processes. The app works without either; you just lose
features rather than getting errors.

```bash
php artisan queue:work     # AI analysis, matching, notifications
php artisan reverb:start   # WebSocket push
```

Without the queue worker, reports still save — the AI work simply sits in
the `jobs` table until a worker runs. Without Reverb, notifications still
arrive; they just appear on page load instead of instantly, because the
database is the source of truth and the WebSocket is only delivery.

### The AI service

It lives in `../ai-service` (Python, FastAPI, FAISS). See its own README
for setup; the short version:

```bash
cd ../ai-service
python -m venv .venv
.venv/Scripts/python.exe -m pip install -r requirements.txt
.venv/Scripts/python.exe -m uvicorn main:app --port 8001
```

Set `AI_SERVICE_ENABLED=false` in `.env` to run without it entirely.
Reporting, verification, claims, notifications, keyword search and
text/category/location/time matching all keep working. Only image
similarity is lost.

To move the AI service to a cloud host later, change one line:

```
AI_SERVICE_URL=https://your-host.example.com
```

No Laravel code changes, no migration, and the React app never finds out —
it only ever talks to Laravel.

**After `migrate:fresh`,** the vector index still holds vectors for item
ids that no longer exist. Rebuild with:

```bash
rm -rf ../ai-service/storage
php artisan ai:reindex --fresh
php artisan queue:work
```

---

## Testing

```bash
php artisan test                        # everything
php artisan test --filter=SecurityTest  # just the security guarantees
```

Tests run against `lost_and_found_test` on **MySQL**, not sqlite. The schema
depends on MySQL behaviour (enum columns, and the implicit-timestamp rule
that forced `lost_at`/`found_at` to be `datetime`), so sqlite would pass
while production differed.

`SecurityTest` is the checklist version of the security requirements — one
test per promise, so a failure names the guarantee that broke.

---

## How the code is arranged

```
Request -> Form Request (validate) -> Controller (authorise) -> Service (do) -> Model
```

Controllers stay thin. If something looks like a business rule, it lives in
a service.

| Where | What |
|---|---|
| `app/Enums` | Roles and statuses as typed enums, with the rules attached |
| `app/Policies` | Who may do what. Action-level *and* field-level |
| `app/Services` | Business logic, transactions, audit entries |
| `app/Services/Ai` | The AI boundary — the only code that knows FastAPI exists |
| `app/Services/Matching` | Hybrid scoring. Weights live in `config/matching.php` |
| `app/Jobs` | Anything slow enough that a user should not wait for it |
| `app/Http/Resources` | JSON shaping. camelCase, string ids, per-viewer fields |

### Two conventions worth knowing before you edit anything

**1. Responses are unwrapped and camelCase.** No `{success, data}` envelope —
the React services read the resource directly, and `JsonResource::withoutWrapping()`
in `AppServiceProvider` is what makes that true. Lists return
`{data, meta: {currentPage, lastPage, perPage, total}}`. Ids are strings,
because the TypeScript types say `id: string`.

**2. Authorisation is two layers, and you need both.**

- Route middleware (`role:admin`) — "should this kind of user be in this
  section at all?" It runs before the record is resolved, so that is the
  only question it can answer.
- Policies — "may *this* user touch *this* record?"

A route being behind `role:staff` never excuses a controller from calling
`authorize()`.

---

## Security notes

Things that are easy to break without noticing:

- **Photos are served by a controller**, not from a public symlink. They
  include handed-in student IDs and phone lock screens. `ItemImagePolicy`
  runs before any bytes are sent, and delegates to the parent item's rules
  so a photo can never be more visible than the item it belongs to.
- **`found_items.private_verification_info` is the evidence a claim is
  checked against.** If a browsing student could read it, they could recite
  it back and claim an item that is not theirs. It is hidden on the model
  *and* gated in the resource.
- **`claim_verifications.expected_answer` is never sent to the claimant**,
  for the same reason.
- **Denials are 404, not 403, for records you should not know exist** —
  another student's claim, an unverified found item. A 403 confirms the
  record is there, which is itself a leak.
- **`role` and `status` are not mass-assignable.** Public registration
  always produces a student, enforced in three independent places.
- **Nobody approves their own claim** — not the claimant, not a staff
  member who filed it, and admins do not bypass the anti-self-dealing rule.
- **`matchId` on a claim is checked for ownership**, not just existence.
  Without that, a student could attach another student's AI match to
  their own claim — forging the "they reported losing this last week"
  corroboration staff rely on, and closing the victim's real report when
  the claim completed.
- **Two students can both claim an item, but only one can be approved.**
  Rejecting the losing claim does not put the item back on the board.
- **Every API route is rate limited** (120/min per user, 30/min per
  guest IP), with a tighter bucket on item images — they are the most
  sensitive thing served and the easiest to scrape.
- **Uploaded photos are re-encoded**, which strips EXIF. Phone cameras
  embed GPS coordinates, and any signed-in user can view a lost report's
  photo.

Each of these has a test. If you change one, the test will tell you which
promise you broke.

### Reviews

This codebase was audited by two review passes. Findings and fixes are
covered by `SecurityTest`, `ClaimMatchOwnershipTest`, `CompetingClaimsTest`
and `MatchingRegressionTest`.

One lesson worth keeping: `Model::preventLazyLoading` only arms on result
sets with **more than one row**, so a suite built entirely on single-model
fetches cannot catch a missing eager-load. `MatchingRegressionTest`
deliberately creates several candidates for that reason.

---

## Useful commands

```bash
php artisan app:create-staff                 # provision a staff/admin account
php artisan migrate:fresh --seed             # reset the database
php artisan queue:work                       # process queued AI jobs
php artisan queue:failed                     # see what failed
php artisan route:list --path=api            # every endpoint
```
