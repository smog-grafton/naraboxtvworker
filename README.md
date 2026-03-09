# NaraboxTV File Server Worker (Transcoder)

Laravel-based media processing worker for the NaraboxTV stack. It runs **transcoding**, **probing**, and **sync** jobs (via Laravel Horizon and Redis), talks to **CDN** (cdn.naraboxtv.com) and **Portal** (portal.naraboxtv.com) over HTTP APIs, and can be deployed on **Coolify** or run locally (e.g. with XAMPP).

---

## Table of contents

- [What this worker does](#what-this-worker-does)
- [Technologies used](#technologies-used)
- [Databases and infrastructure](#databases-and-infrastructure)
- [How it fits with Portal and CDN](#how-it-fits-with-portal-and-cdn)
- [Local setup (Mac / XAMPP)](#local-setup-mac--xampp)
- [Coolify deployment](#coolify-deployment)
- [Worker API (incoming)](#worker-api-incoming)
- [Environment variables](#environment-variables)
- [Filament admin](#filament-admin)
- [Integration summary](#integration-summary)
- [Further reading](#further-reading)

---

## What this worker does

The **NaraboxTV File Server Worker** (also called “transcoder” or “Laravel worker”) is responsible for:

1. **Processing requests** — Accepts jobs from the CDN (or Portal) to process a media source: download, probe, transcode (e.g. MP4 faststart), generate HLS, then report results back.
2. **Transcoding** — Uses **FFmpeg** (and **FFprobe**) to optimize video (e.g. faststart) and generate HLS variants. Jobs run on the `transcode` queue with long timeouts (e.g. 7200s).
3. **Probing** — Media inspection (duration, codecs, resolution) on the `probe` queue.
4. **Sync** — After processing, the worker can notify the **Portal** so it can refresh derived video sources (e.g. mp4_play, hls_master) for playback. Sync jobs use the `sync` queue.
5. **Callbacks** — When processing finishes, the worker calls the **CDN** (`POST /api/v1/media/worker/callback`) to report success or failure and update the source’s `optimize_status`, `optimized_path`, `hls_master_path`, etc.

**Current pipeline (Phase 1/2):** The main job `ProcessMediaPipelineJob` creates a `ProcessingRequest`, advances status (e.g. Downloading → Downloaded), and logs; the full chain (download → probe → transcode → HLS → upload → callback → sync) is partially implemented with placeholders. The API, Filament UI, and CDN/Portal integration points are in place.

---

## Technologies used

| Technology | Role |
|------------|------|
| **Laravel 12** | App framework, HTTP API, queues, Horizon, Filament. |
| **PHP 8.4** | Runtime (CLI in Docker; optional `php artisan serve` for web/Filament). |
| **Laravel Horizon** | Redis queue supervisor: separate processes for `transcode`, `probe`, `sync` with configurable timeouts and concurrency. |
| **Redis** | Queue driver (`QUEUE_CONNECTION=redis`), cache, and Horizon state. |
| **MySQL** | Processing requests, attempts, callback logs, sync logs, Filament/admin data. |
| **FFmpeg / FFprobe** | Transcoding and media probing (installed in Docker; required on PATH for local). |
| **Filament 3** | Admin panel at `/admin`: Processing Requests, Attempts, Callback Logs, Sync Logs, stats widget. |
| **Docker** | Production image: PHP 8.4 CLI, Composer, Redis extension, FFmpeg; entrypoint runs web server on port 3000 + Horizon. |

---

## Databases and infrastructure

### MySQL

- **Role:** Persist processing lifecycle and admin data.
- **Tables (worker DB):**
  - `processing_requests` — Each job from CDN/Portal (external_id, cdn_asset_id, cdn_source_id, source_url, status, failure_reason, payload, artifact_paths, callback_url, portal_sync_hint, timestamps).
  - `processing_attempts` — Per-request attempt history (for retries and debugging).
  - `callback_logs` — Log of outbound callback requests to the CDN.
  - `sync_logs` — Log of sync requests to the Portal.
  - Laravel defaults: `users`, `cache`, `jobs`, etc. (migrations in `database/migrations/`).
- **Config:** `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. For Coolify MySQL with `require_secure_transport=ON`, set `DB_SSL_MODE=REQUIRED` (see `config/database.php`).

### Redis

- **Role:** Queue backend for Horizon; optional cache/session store.
- **Queues:** `transcode` (heavy, long timeout), `probe`, `sync` (see `config/horizon.php` and `config/media_worker.php`).
- **Config:** `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_CLIENT` (e.g. `phpredis` or `predis`). In Coolify, use the **internal** Redis host/port, not `127.0.0.1`.

### Horizon

- **Role:** Runs queue workers for `transcode`, `probe`, and `sync` with separate supervisors (process count, timeout, memory per queue).
- **Dashboard:** When the web server is running (e.g. `https://worker.naraboxtv.com`), Horizon UI is at `/horizon` (same auth as Laravel).
- **Config:** `config/horizon.php`; env vars `HORIZON_PREFIX`, `HORIZON_TRANSCODE_PROCESSES`, `HORIZON_TRANSCODE_TIMEOUT`, `HORIZON_PROBE_PROCESSES`, `HORIZON_SYNC_PROCESSES`, etc.
- **Scaling:** Each transcode job (download → faststart → HLS → upload) can take **15–60+ minutes** for long videos. Set `HORIZON_TRANSCODE_PROCESSES=4` (or higher) so multiple jobs run in parallel; otherwise one long job blocks the queue and others stay in Pending.

---

## How it fits with Portal and CDN

### Local paths (for reference)

- **Worker:** `/Applications/XAMPP/xamppfiles/htdocs/file-server-worker`
- **Portal (Laravel):** `/Applications/XAMPP/xamppfiles/htdocs/naraboxt-lara` → represents **portal.naraboxtv.com**
- **CDN (Laravel):** `/Applications/XAMPP/xamppfiles/htdocs/naraboxtv-cdn` → represents **cdn.naraboxtv.com**

### Flow (high level)

1. **CDN** has a media source ready (e.g. after import). It can either:
   - Run optimization locally (existing `optimization` queue), or
   - Send the job to the **worker** by calling `POST {WORKER_URL}/api/v1/processing/submit` with Bearer `WORKER_API_TOKEN`, passing `cdn_asset_id`, `cdn_source_id`, `source_url`, and optional `payload` / `callback_url` / `portal_sync_hint`.
2. **Worker** creates a `ProcessingRequest`, dispatches `ProcessMediaPipelineJob` to the `transcode` queue. When the pipeline completes (or fails), it calls the **CDN** at `POST {CDN_URL}/api/v1/media/worker/callback` with `asset_id`, `source_id`, `status` (completed/failed), and optional paths/qualities. The worker **cleans up** temp files and artifacts after processing so the worker VPS does not run out of space; optimized files live on the CDN.
3. **Worker** may call the **Portal** at `POST {PORTAL_URL}/api/v1/worker/sync` with `cdn_asset_id`, `cdn_source_id`, and optional `hint` so the Portal can refresh derived VideoSources for playback. The Portal expects Bearer `PORTAL_WORKER_API_TOKEN` (same value as the worker’s `PORTAL_API_TOKEN`).

### Portal (naraboxt-lara) ↔ Worker

- **Portal exposes:** `POST /api/v1/worker/sync` (Bearer token). Worker calls this after processing so Portal can run `VideoSourceDerivationService::ensureDerivedSourcesForCdnUrl()` for the given CDN asset/source.
- **Portal env:** `PORTAL_WORKER_API_TOKEN` — same secret as the worker’s `PORTAL_API_TOKEN` (used by the worker when calling the Portal).

### CDN (naraboxtv-cdn) ↔ Worker

- **CDN calls worker:** `POST {worker}/api/v1/processing/submit` with Bearer `WORKER_API_TOKEN` (from CDN env: `CDN_LARAVEL_WORKER_API_URL`, `CDN_LARAVEL_WORKER_API_TOKEN`). Enable with `CDN_LARAVEL_WORKER_ENABLED=true`. Set `CDN_LARAVEL_WORKER_API_URL` to the worker’s public URL (e.g. `http://wwwogwgw80cwo4g4skw8oggg.157.173.104.218.sslip.io`).
- **Queue pending sources:** On the CDN, run `php artisan media:queue-pending-for-worker` to send all pending/failed optimization sources to the worker (when worker is enabled). Use `--limit=N` to cap how many are queued.
- **Worker calls CDN:** `POST {CDN}/api/v1/media/worker/callback` with Bearer `CDN_API_TOKEN`. Payload: `asset_id`, `source_id`, `status`, optional `optimized_path`, `hls_master_path`, `qualities_json`, `is_faststart`, `playback_type`, `failure_reason`.

---

## Local setup (Mac / XAMPP)

### 1. Clone and install

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/file-server-worker
composer install
cp .env.example .env
php artisan key:generate
```

### 2. Environment

- **APP_KEY:** Must be set. Run `php artisan key:generate` after copying `.env.example` to `.env`.
- **Queue:** `QUEUE_CONNECTION=redis`
- **Redis:** `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379` (or your Redis). Use `REDIS_CLIENT=predis` if the PHP Redis extension is not installed.
- **Session (local without Redis):** Set `SESSION_DRIVER=file` so `/` and `/admin` work without a running Redis; use `SESSION_DRIVER=redis` when Redis is running (e.g. for Horizon).
- **MySQL:** Set `DB_*` to your local DB (e.g. `DB_DATABASE=file-server-worker`, `DB_USERNAME=root`, `DB_PASSWORD=` for XAMPP).
- **CDN/Portal (optional for local):** Point `CDN_API_BASE_URL` / `PORTAL_API_BASE_URL` to local URLs if you run CDN/Portal locally.

**Local token setup:**

- **PORTAL_API_TOKEN:** Must match the Portal’s `PORTAL_WORKER_API_TOKEN` (in naraboxt-lara `.env`). The worker uses this when calling `POST /api/v1/worker/sync`.
- **CDN_API_TOKEN:** Used when the worker calls the CDN callback `POST /api/v1/media/worker/callback`. Create a token in the CDN app: `cd naraboxtv-cdn && php artisan cdn:token worker-callback`, then set that value in the worker’s `.env`. You can reuse the same token as telebot if it’s a valid CDN API token.

### 3. Redis

Horizon (and `/horizon` dashboard APIs like `/horizon/api/stats`, `/horizon/api/masters`, `/horizon/api/workload`) **requires Redis**. Without Redis running, those endpoints return 500. For local dev you can use `SESSION_DRIVER=file` and `CACHE_STORE=file` so the main site and Filament login work without Redis; Horizon will still need Redis.

```bash
# Homebrew
brew install redis && brew services start redis
```

### 4. Database

```bash
php artisan migrate
```

### 5. Filament admin user (optional)

```bash
php artisan make:filament-user
```

### 6. Run the app

- **Web (Filament + Horizon UI):** From project root, either:
  - Use Apache (XAMPP) with document root pointing to `public/`, or use the root `.htaccess` that rewrites to `public/` (so `http://worker.naraboxtv.test` or similar serves the app).
  - Or: `php artisan serve` (e.g. `http://localhost:8000`). Then open `/admin` and `/horizon`.
- **Queue worker:** In a separate terminal run Horizon:
  ```bash
  php artisan horizon
  ```
  Or a single queue: `php artisan queue:work redis --queue=transcode,probe,sync`.

### 7. Test FFmpeg and a job

```bash
php artisan ffmpeg:test
php artisan worker:dispatch-healthcheck
```

Check Horizon or logs for the healthcheck job.

---

## Coolify deployment

The Dockerfile uses **byjg/php:8.4-cli** (PHP 8.4 with intl, redis, pdo_mysql, etc. pre-installed) so the image builds quickly without compiling PHP extensions. If you previously saw the build fail during `docker-php-ext-install intl`, that was due to timeout or resource limits; the current image avoids that.

If Coolify shows a warning about `APP_ENV=production` at build time, set **APP_ENV** (and other runtime-only vars) as **Runtime only** in the environment variables settings so they are not injected during the build.

### Prerequisites

- Repo on GitHub: [https://github.com/smog-grafton/naraboxtvworker](https://github.com/smog-grafton/naraboxtvworker)
- Coolify project with **MySQL** and **Redis** (or external). Use **internal** hostnames/ports for the worker container.

### Add the application in Coolify

1. **Projects** → your project → **Add Resource** → **Application**.
2. **Source:** GitHub (or public repo), branch `main`.
3. **Build pack:** Dockerfile (root `Dockerfile`).
4. **Port:** The container exposes **3000**. In Coolify, set **Port Exposes** (or equivalent) to **3000** so the proxy (Traefik/Caddy) routes HTTP to the container. The Dockerfile runs `docker-entrypoint.sh`: `php artisan serve --host=0.0.0.0 --port=3000` plus `php artisan horizon`.
5. **Domains:** Add the Coolify-generated domain (e.g. `http://xxx.157.173.104.218.sslip.io`) first; after it works, add `https://worker.naraboxtv.com` (and set DNS A record for `worker.naraboxtv.com` to the server IP).

### Environment variables (Coolify)

Set at least:

| Variable | Description |
|----------|-------------|
| `APP_KEY` | From `php artisan key:generate --show` |
| `APP_ENV` | `production` |
| `APP_URL` | `https://worker.naraboxtv.com` (or your domain) |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | **Internal** MySQL hostname (from Coolify MySQL “MySQL URL (internal)” — the host between `@` and `:3306`), **not** `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_DATABASE` | Database name (e.g. `default` or `worker`) |
| `DB_USERNAME` / `DB_PASSWORD` | From Coolify MySQL |
| `DB_SSL_MODE` | `REQUIRED` if MySQL uses `require_secure_transport=ON` |
| `QUEUE_CONNECTION` | `redis` |
| `REDIS_HOST` | **Internal** Redis hostname (from Coolify Redis “Redis URL (internal)”), **not** `127.0.0.1` |
| `REDIS_PORT` | From internal URL (e.g. `6379` or `6380`) |
| `REDIS_PASSWORD` | If set |
| `REDIS_CLIENT` | `phpredis` (or `predis`) |
| `CDN_API_BASE_URL` | `https://cdn.naraboxtv.com` (or your CDN URL) |
| `CDN_API_TOKEN` | Bearer token for worker → CDN callback |
| `PORTAL_API_BASE_URL` | `https://portal.naraboxtv.com` (or your Portal URL) |
| `PORTAL_API_TOKEN` | Bearer token for worker → Portal sync (same value as Portal’s `PORTAL_WORKER_API_TOKEN`) |
| `WORKER_API_TOKEN` | Bearer token for CDN/Portal → worker API (submit, status, retry) |

Optional: `WORKER_TEMP_DIR`, `FFMPEG_BIN`, `FFPROBE_BIN`, `TRANSCODE_QUEUE`, `PROBE_QUEUE`, `SYNC_QUEUE`, `HORIZON_*` (see `.env.example`).

### After deploy

- Run migrations (Coolify “Execute command” or one-off container): `php artisan migrate --force`
- Create Filament user if needed: `php artisan make:filament-user`
- Verify: open the app domain → `/admin` and `/horizon`; dispatch a test job and check logs.

### Bad Gateway (502)

If the proxy shows 502, ensure the app inside the container listens on **port 3000** and on **0.0.0.0** (the Dockerfile and `docker-entrypoint.sh` do this). Ensure Coolify “Port Exposes” (or proxy config) is **3000**.

---

### 500 Internal Server Error on / or /admin

The container has **no `.env` file** (it’s in `.dockerignore`). The app **relies only on Coolify (or runtime) environment variables**; no `.env` is written in the container. Laravel reads config via `getenv()`. If you still get 500, check the following.

1. **APP_KEY is required**  
   In Coolify, set **APP_KEY** to a valid Laravel key. Generate one locally:
   ```bash
   cd /path/to/file-server-worker && php artisan key:generate --show
   ```
   Paste that value into Coolify’s **APP_KEY** (as a secret or normal env var). If APP_KEY is missing or empty, Laravel returns 500 when starting the session or encrypting cookies.

2. **Redis unreachable (session)**  
   If **SESSION_DRIVER=redis** and the app cannot reach Redis, every web request that uses the session will 500. Ensure **REDIS_HOST** (and **REDIS_PORT** / **REDIS_PASSWORD**) use Coolify’s **internal** Redis hostname, not `127.0.0.1`. To confirm Redis is the cause, temporarily set **SESSION_DRIVER=file** and **CACHE_STORE=file** in Coolify; if the 500 goes away, fix Redis connectivity and then switch back to `redis`.

3. **See the real error**  
   Set **APP_DEBUG=true** in Coolify (temporarily), redeploy, and open `/` or `/admin` again. The response will show the exception and message. Fix the cause, then set **APP_DEBUG=false** again.

4. **Artisan in the container**  
   Set **APP_KEY** in Coolify (as above). You can run other commands (e.g. `php artisan migrate --force`) in Coolify’s “Execute command” / terminal; they use the same Coolify env vars.

### APP_URL and production URLs

Set **APP_URL** in Coolify to the worker’s **public URL** (e.g. `http://wwwogwgw80cwo4g4skw8oggg.157.173.104.218.sslip.io` or your custom domain). This is used for redirects and for the Horizon dashboard’s API requests. Recommended base URLs:

- **Worker:** your Coolify app URL (e.g. the sslip.io URL above, or `https://worker.naraboxtv.com`)
- **Portal:** `https://portal.naraboxtv.com` (set **PORTAL_API_BASE_URL**)
- **CDN:** `https://cdn.naraboxtv.com` (set **CDN_API_BASE_URL**)

### Horizon dashboard: 500 on /horizon/api/masters, /horizon/api/stats, etc.

Those endpoints read from **Redis**. If they return 500:

1. Ensure **REDIS_HOST**, **REDIS_PORT**, and **REDIS_PASSWORD** in Coolify use the **internal** Redis host (Coolify Redis service name), not `127.0.0.1`.
2. Do **not** run `php artisan config:cache` in the container if config is loaded from env at runtime; if you did, run `php artisan config:clear` in Coolify’s “Execute command”.
3. Set **APP_DEBUG=true** temporarily and open `/horizon` again; the response body or `storage/logs/laravel.log` will show the Redis (or other) exception.

---

## Worker API (incoming)

All routes are under `/api/v1`, protected by middleware that validates `Authorization: Bearer {WORKER_API_TOKEN}`.

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/v1/processing/submit` | Submit a processing request. Body: `source_url` (required), `cdn_asset_id`, `cdn_source_id`, `original_filename`, `callback_url`, `portal_sync_hint`, `payload`. Returns `202` with `external_id`, `status`, `received_at`. |
| `GET` | `/api/v1/processing/{externalId}` | Status of a request (UUID). Returns status, timestamps, attempt/callback/sync counts. |
| `POST` | `/api/v1/processing/{externalId}/retry` | Retry a failed or cancelled request (dispatches a new pipeline job). |

---

## Environment variables

See `.env.example`. Summary:

- **App:** `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`
- **DB:** `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSL_MODE` (optional, use `REQUIRED` for Coolify MySQL with SSL)
- **Redis:** `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`
- **CDN (outbound):** `CDN_API_BASE_URL`, `CDN_API_TOKEN`
- **Portal (outbound):** `PORTAL_API_BASE_URL`, `PORTAL_API_TOKEN`
- **Worker API (inbound):** `WORKER_API_TOKEN`
- **Media:** `WORKER_TEMP_DIR`, `FFMPEG_BIN`, `FFPROBE_BIN`, `TRANSCODE_QUEUE`, `PROBE_QUEUE`, `SYNC_QUEUE`
- **Horizon:** `HORIZON_PREFIX`, `HORIZON_TRANSCODE_PROCESSES`, `HORIZON_TRANSCODE_TIMEOUT`, `HORIZON_PROBE_PROCESSES`, `HORIZON_SYNC_PROCESSES`, etc.

---

## Filament admin

- **URL:** `{APP_URL}/admin` (e.g. `https://worker.naraboxtv.com/admin`).
- **Create user:** `php artisan make:filament-user`.
- **Resources:** Processing Requests (list, view, retry); relation managers for Attempts, Callback Logs, Sync Logs.
- **Widget:** Processing requests stats on the dashboard.

---

## Integration summary

| From | To | Action |
|------|----|--------|
| CDN | Worker | `POST /api/v1/processing/submit` (Bearer `WORKER_API_TOKEN`) |
| Worker | CDN | `POST /api/v1/media/worker/callback` (Bearer `CDN_API_TOKEN`) |
| Worker | Portal | `POST /api/v1/worker/sync` (Bearer `PORTAL_API_TOKEN` = Portal’s `PORTAL_WORKER_API_TOKEN`) |

**Config files:** `config/media_worker.php` (queues, CDN/Portal URLs and tokens, API token). Integration details: `docs/INTEGRATION_PLAN.md`.

---

## Further reading

- [docs/INTEGRATION_PLAN.md](docs/INTEGRATION_PLAN.md) — Worker, CDN, Portal architecture and phases.
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [Filament](https://filamentphp.com/docs)
- [Coolify](https://coolify.io/docs) — Deployment and proxy (Traefik/Caddy).
