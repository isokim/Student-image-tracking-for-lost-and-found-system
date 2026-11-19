# Student Lost & Found System

A web app for reporting, searching for and recovering lost items on campus, with AI-assisted photo matching.

- **Students** report lost items, search found items (by text or by photo), and claim items that are theirs.
- **Staff** log items handed in at the lost & found office, review claims side by side with the finder's hidden details, and record when an item is returned.
- When staff log an item that matches a student's lost report, the student gets a **"Possible match"** notification automatically.

| Folder | What it is | Built with | Runs on |
|---|---|---|---|
| `backend/` | API, database and business rules | Laravel 12 (PHP), MySQL | http://localhost:8002 |
| `lost-and-found/` | The website | React + TypeScript (Vite) | http://localhost:5173 |
| `ai-service/` | Photo matching and photo search | Python (FastAPI, SigLIP, FAISS) | http://127.0.0.1:8001 |

---

## 1. What you need installed

| Tool | Version | Used for |
|---|---|---|
| [XAMPP](https://www.apachefriends.org/) (or PHP + MySQL separately) | PHP 8.2+, MySQL 8 / MariaDB | Backend and database |
| [Composer](https://getcomposer.org/) | 2.x | PHP packages |
| [Node.js](https://nodejs.org/) | 22 LTS (20.19+ minimum) | The website |
| [Python](https://www.python.org/) | 3.11 or newer | The AI service |
| [Git](https://git-scm.com/) | any | Downloading the project |
| [Ollama](https://ollama.com/) *(optional)* | any | Written descriptions of photos |

About **4 GB of free disk space** is needed for the AI packages and model. A computer with **8 GB of RAM** is enough.

---

## 2. Download the project

```powershell
git clone https://github.com/isokim/Student-image-tracking-for-lost-and-found-system.git
cd Student-image-tracking-for-lost-and-found-system
```

---

## 3. Create the database

Start **MySQL** from the XAMPP Control Panel, open **phpMyAdmin** (http://localhost/phpmyadmin) → **SQL**, and run:

```sql
CREATE DATABASE lost_and_found      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE lost_and_found_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## 4. Set up the backend (Laravel)

```powershell
cd backend
composer install
copy .env.example .env
php artisan key:generate
```

Open `backend/.env` and check:

- `DB_USERNAME` / `DB_PASSWORD` match your MySQL login (XAMPP's default is `root` with no password).
- `AI_SERVICE_TOKEN` — pick any secret word. **It must be the same in `ai-service/.env`** (step 5).

Then create the tables and the starter data:

```powershell
php artisan migrate --seed
```

---

## 5. Set up the AI service (Python)

```powershell
cd ..\ai-service
python -m venv .venv
.venv\Scripts\python.exe -m pip install -r requirements.txt -r requirements-siglip.txt
copy .env.example .env
```

This downloads about 2.5 GB, so it can take a while.

Open `ai-service/.env` and set `AI_SERVICE_TOKEN` to **the same value as in `backend/.env`**.

The first time the service starts it downloads the SigLIP model (~400 MB). After that it's stored on your computer.

> **No AI?** The app still works without this service — set `AI_SERVICE_ENABLED=false` in `backend/.env`. Reporting, claims, text search, notifications and text-based matching keep working; only photo search and photo similarity are lost.

---

## 6. Set up the website (React)

```powershell
cd ..\lost-and-found
npm install
copy .env.example .env
```

The default `VITE_API_URL=http://localhost:8002/api` is correct unless you changed the backend's port.

---

## 7. Optional: photo descriptions with Ollama

Install [Ollama](https://ollama.com/), then download the small vision model:

```powershell
ollama pull moondream
```

In `ai-service/.env` set:

```
OLLAMA_VISION_MODEL=moondream
```

Ollama runs in the background on its own — you don't need to run `ollama serve`. Moondream needs about 1.7 GB of RAM. Larger models such as `llava:7b` need about 4 GB and often won't load on an 8 GB computer. Everything except the written descriptions works without Ollama.

---

## Running the project

Start **MySQL** in XAMPP, then open **three terminals**:

**Terminal 1 — AI service**
```powershell
cd ai-service
.venv\Scripts\python.exe -m uvicorn main:app --port 8001
```
Ready when you see: `ai-service: Ready. embedder=siglip ...`

**Terminal 2 — backend and job worker**
```powershell
cd backend
composer run dev
```
This starts the API (port 8002) and the background worker that handles photo processing, matching and notifications.

**Terminal 3 — website**
```powershell
cd lost-and-found
npm run dev
```

Open **http://localhost:5173**.

### Accounts

| Role | How to get one |
|---|---|
| Student | Click **Register** on the website |
| Staff | `staff@lostandfound.test` / `password` (created by `migrate --seed`) |

These are local development accounts only. Change the password before using the system anywhere real.

### Testing as a student and staff at the same time

Login uses a browser cookie, so all tabs on the same address share one account. To be logged in as two accounts at once:

- open one tab on **http://localhost:5173** and another on **http://127.0.0.1:5173**, or
- use a private window, a second browser, or Firefox **Multi-Account Containers**.

---

## Running the tests

```powershell
cd backend
php artisan test
```

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Photo matching never happens / no "Possible match" | Make sure Terminal 1 (AI service) and Terminal 2 (`composer run dev`, which includes the worker) are both running. |
| AI service log shows `401` errors | `AI_SERVICE_TOKEN` is different in `backend/.env` and `ai-service/.env`. Make them identical. |
| AI service hangs on start with Hugging Face connection errors | The model is already downloaded but the internet check is failing. Add `HF_HUB_OFFLINE=1` and `TRANSFORMERS_OFFLINE=1` to `ai-service/.env`. |
| "Skipping image analysis" in the AI service log | Ollama couldn't load the model — usually not enough free RAM. Use `moondream`, or quit Ollama. Matching still works. |
| `ollama serve` says the address is already in use | Ollama is already running in the background. Nothing to do. |
| Login doesn't stick | Use `http://localhost:5173` (or `127.0.0.1:5173`) and keep the backend on port 8002, or update the `.env` files to match. |
| Port already in use | Check with `netstat -ano \| findstr ":8002 :8001 :5173"` and close the other program. |

More detail on each part: [`backend/README.md`](backend/README.md) and [`ai-service/README.md`](ai-service/README.md).
