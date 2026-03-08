# Changes needed in CDN and Portal to integrate with the worker

The worker is ready to receive jobs and will eventually call back into CDN and Portal. Below are the changes you need in **CDN** (naraboxtv-cdn) and **Portal** (naraboxt-lara) to complete the integration.

---

## CDN (naraboxtv-cdn)

### 1. Config: Laravel worker URL and token

So the CDN can **send** processing work to the worker (e.g. after a source is downloaded).

- **File:** `config/cdn.php`
- **Add** (same pattern as `python_worker_*`):
  - `laravel_worker_enabled` => `env('CDN_LARAVEL_WORKER_ENABLED', false)`
  - `laravel_worker_api_url` => `env('CDN_LARAVEL_WORKER_API_URL', '')`  
    (e.g. `https://worker.naraboxtv.com` — no trailing slash)
  - `laravel_worker_api_token` => `env('CDN_LARAVEL_WORKER_API_TOKEN', '')`  
    (same value as the worker’s `WORKER_API_TOKEN`)

- **.env (CDN):** add:
  - `CDN_LARAVEL_WORKER_ENABLED=true` (when you want to use the worker)
  - `CDN_LARAVEL_WORKER_API_URL=https://worker.naraboxtv.com`
  - `CDN_LARAVEL_WORKER_API_TOKEN=<same as worker WORKER_API_TOKEN>`

### 2. Send playback processing to the worker (optional path)

When a source is **ready** (file already on CDN), instead of running `OptimizeMp4FaststartJob` and `GenerateHlsVariantsJob` on the CDN, the CDN can submit a job to the worker. The worker will need a **download URL** for that file (e.g. CDN stream URL or a temporary signed URL).

- **Option A – New method in `MediaSourceService`:**  
  e.g. `queueLaravelWorkerPlaybackProcessing(MediaSource $source)`:  
  - If `laravel_worker_enabled` and URL/token are set:  
    `Http::withToken($token)->post($baseUrl . '/api/v1/processing/submit', [  
      'cdn_asset_id' => $source->media_asset_id,  
      'cdn_source_id' => $source->id,  
      'source_url' => <URL where worker can download this source file>,  
      'original_filename' => basename($source->storage_path),  
      'callback_url' => optional,  
      'payload' => optional,  
    ])`  
  - Else: keep current behaviour and call `queuePlaybackProcessing($source)` (run optimization on CDN).

- **Option B – Reuse import flow:**  
  If you prefer the worker to do “download + optimize” only when using `import_strategy=laravel_worker`, you can add `'laravel_worker'` to the import strategy and in the API validation, and in that case call the worker’s submit with `source_url` = original import URL (and optionally `cdn_asset_id` / `cdn_source_id` after you’ve created the asset/source). Then the worker downloads from that URL and processes; when done it callbacks CDN (see below).

- **Where to call the new “send to worker” path:**  
  Replace or branch in:
  - `MediaSourceService::queuePlaybackProcessing()` — e.g. if Laravel worker enabled, call `queueLaravelWorkerPlaybackProcessing()` and return; else existing Bus::chain(OptimizeMp4FaststartJob, GenerateHlsVariantsJob).
  - And/or after `ImportRemoteMediaSourceJob` completes (instead of `queuePlaybackProcessing($source->fresh())`), call the worker submit with a URL the worker can use to download the file (e.g. your CDN stream URL for that source).

So: **either** add a “playback processing via worker” path (Option A) **or** an import-strategy path (Option B), and wire it with the new config.

### 3. Worker callback endpoint (required for worker → CDN)

The worker will call the CDN when processing finishes (success or failure). The worker uses:

- **URL:** `POST {CDN_API_BASE_URL}/api/v1/media/worker/callback`
- **Auth:** same as existing CDN API: `Authorization: Bearer {CDN_API_TOKEN}` (worker already has `CDN_API_TOKEN` in its config).
- **Body (example):**  
  `asset_id`, `source_id`, `status` (e.g. `completed` / `failed`), `failure_reason` (if failed), `optimized_path`, `hls_master_path`, `qualities_json`, etc.

**Add in CDN:**

- **Route:** In `routes/api.php`, inside the same `v1` + `cdn.token` group (or a dedicated route that uses the same token), add:
  - `Route::post('/media/worker/callback', [MediaController::class, 'workerCallback'])`
  - or a dedicated `WorkerCallbackController`.

- **Controller logic:** e.g. `workerCallback(Request $request)`:
  - Validate: `asset_id` (uuid), `source_id` (int), `status` (string).
  - Find `MediaSource` by `media_asset_id` and `id`; optionally verify token/scoped to that resource.
  - If `status === 'completed'`: update source with `optimized_path`, `hls_master_path`, `qualities_json`, `optimize_status = 'ready'`, `is_faststart`, `playback_type`, `optimized_at`, clear `optimize_error`.
  - If `status === 'failed'`: set `optimize_status = 'failed'`, `optimize_error` = request payload message.
  - Return 200 JSON so the worker can log success.

**File upload from worker:**  
If the worker will **upload** the optimized MP4 and HLS files to the CDN (instead of only sending paths), add an endpoint (e.g. `POST /api/v1/media/worker/upload`) that accepts multipart with `asset_id`, `source_id`, and files; store under the same disk/path layout (`media/{uuid}/{sourceId}/...`) and then update the same `MediaSource` columns. The callback can then be “metadata only” or confirm after upload. If the worker only sends paths, the callback is the only endpoint and the worker must have uploaded files via some other mechanism (e.g. shared storage or a separate upload step you define).

---

## Portal (naraboxt-lara)

### 1. Worker sync endpoint (optional but recommended)

The worker can notify the Portal when a CDN source’s playback is ready (e.g. so the Portal can refresh or re-derive video sources). The worker uses:

- **URL:** `POST {PORTAL_API_BASE_URL}/api/v1/worker/sync`
- **Auth:** `Authorization: Bearer {PORTAL_API_TOKEN}` (worker has `PORTAL_API_TOKEN` in its config).
- **Body (example):** `hint` (e.g. `cdn_asset_id:cdn_source_id` or a string you agree with the worker), plus payload (e.g. `asset_id`, `source_id`, `playback_ready`).

**Add in Portal:**

- **Route:** In `routes/api.php` (e.g. under `v1`), add:
  - `Route::post('/worker/sync', [WorkerSyncController::class, 'sync'])`
  - Protect with a middleware that validates the Bearer token (e.g. a custom middleware that checks a fixed token or a dedicated API token for the worker). Portal does not use `cdn.token`; use a token the worker will send (same value as worker’s `PORTAL_API_TOKEN`).

- **Controller logic:** e.g. `sync(Request $request)`:
  - Validate `hint` and any required fields.
  - Optional: find related content (e.g. video_sources by cdn_asset_id / cdn_source_id in metadata) and refresh or trigger `VideoSourceDerivationService::ensureDerivedSourcesForCdnUrl()` if needed.
  - Return 200 JSON.

If you don’t need the worker to notify the Portal (e.g. editors always paste the CDN URL and derivation runs then), you can skip this and leave the worker’s Portal sync call as a no-op or remove it later.

### 2. Portal env

- **.env (Portal):** ensure you have a token the worker will use for this sync endpoint (e.g. generate a long random string). The worker already has `PORTAL_API_BASE_URL` and `PORTAL_API_TOKEN`; the Portal must accept that same token (e.g. in middleware or in a small token table) for `POST /api/v1/worker/sync`.

---

## Summary table

| Where   | Change |
|--------|--------|
| **CDN** | Add config: `laravel_worker_enabled`, `laravel_worker_api_url`, `laravel_worker_api_token` (and .env). |
| **CDN** | Optionally: send playback processing to worker (new method + branch in `queuePlaybackProcessing` or after import). |
| **CDN** | Add `POST /api/v1/media/worker/callback` (and optionally `/media/worker/upload`), auth with existing CDN token; update `MediaSource` from callback payload. |
| **Portal** | Add `POST /api/v1/worker/sync` and auth (e.g. Bearer token = worker’s `PORTAL_API_TOKEN`). Optional: refresh or re-derive video sources from payload. |

No changes are required to the **worker** repo for these; it already calls `CDN_API_BASE_URL` + `/api/v1/media/worker/callback` and `PORTAL_API_BASE_URL` + `/api/v1/worker/sync` with the tokens you configured.
