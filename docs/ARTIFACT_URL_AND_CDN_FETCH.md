# Artifact URL and CDN fetch

## Is it OK that the artifact URL returns 401 in a browser?

**Yes.** The artifact download endpoint `GET /api/v1/artifacts/{token}` is protected by **Bearer token** (worker API token). Opening that URL in a browser sends no `Authorization` header, so the worker correctly returns:

```json
{"success":false,"error":"Missing or invalid API token."}
```

Only the **CDN** (or any client that has the token) should fetch the ZIP. The CDN uses the same token when it receives the callback and runs `FetchWorkerHlsArtifactJob`.

---

## How the CDN fetches the artifact

1. **Worker** finishes processing and calls the CDN:  
   `POST {CDN}/api/v1/media/worker/callback`  
   with `artifact_download_url`, `artifact_expires_at`, `asset_id`, `source_id`, etc.

2. **CDN** finds the `MediaSource` by `asset_id` + `source_id`, saves `hls_worker_artifact_url` and `hls_worker_status = 'artifact_ready'`, and dispatches **FetchWorkerHlsArtifactJob**.

3. **FetchWorkerHlsArtifactJob** does:
   - `Http::withToken(config('cdn.laravel_worker_api_token'))->get($source->hls_worker_artifact_url)`
   - Saves the response body (ZIP) to temp storage, extracts it, validates `master.m3u8` and variants, moves contents to the source’s `media/{asset_id}/{source_id}/hls/`, updates the source (e.g. `hls_master_path`, `playback_type = 'hls'`), then calls the worker’s ack endpoint.

So the CDN **must** have the worker’s API token so that `withToken(...)` matches the worker’s `worker.api` middleware.

---

## CDN env vars (for send + receive)

On the **CDN** (naraboxtv-cdn), set:

| Variable | Description |
|----------|-------------|
| `CDN_LARAVEL_WORKER_ENABLED` | `true` to allow submitting jobs to the worker. |
| `CDN_LARAVEL_WORKER_API_URL` | Worker base URL (e.g. `http://wwwogwgw80cwo4g4skw8oggg.157.173.104.218.sslip.io` or `https://worker.naraboxtv.com`). |
| `CDN_LARAVEL_WORKER_API_TOKEN` | **Same value as the worker’s `WORKER_API_TOKEN`.** Used when the CDN fetches the artifact ZIP and when it calls the worker ack. |

Optional:

- `CDN_LARAVEL_WORKER_PULL_ENABLED` – `true` (default) so the CDN will fetch artifacts when the callback includes `artifact_download_url`.
- `CDN_WORKER_ARTIFACT_FETCH_TIMEOUT` – e.g. `600` (seconds).
- `CDN_HLS_ARTIFACTS_QUEUE` – queue name for the fetch job (default `optimization`).

---

## Manual requests vs CDN-submitted requests

- **Manual request** (from the worker Filament UI: “New manual request”):  
  `cdn_asset_id` and `cdn_source_id` are **null**. The worker **does not** call the CDN callback, so the CDN never receives `artifact_download_url` and never runs the fetch job. The artifact is only available on the worker (e.g. “Copy artifact URL” in Filament) until it expires.

- **CDN-submitted request**:  
  The CDN submits with `cdn_asset_id` and `cdn_source_id` (existing MediaSource). When the worker finishes, it calls the CDN callback with those ids and `artifact_download_url`. The CDN then fetches the ZIP and installs HLS.

To **see the CDN in action** (worker → callback → CDN fetch → install):

1. On the **CDN** (naraboxtv-cdn): set `CDN_LARAVEL_WORKER_ENABLED=true`, `CDN_LARAVEL_WORKER_API_URL` = worker base URL, `CDN_LARAVEL_WORKER_API_TOKEN` = worker’s `WORKER_API_TOKEN`.
2. Create or use an asset/source on the CDN, then submit it to the worker (e.g. Filament “Send to Laravel worker” if available, or run `php artisan media:queue-pending-for-worker --limit=1` to queue pending sources).
3. When the worker finishes, it callbacks the CDN with `artifact_download_url`; the CDN dispatches **FetchWorkerHlsArtifactJob**, which fetches the ZIP (with the token), extracts it, and installs HLS under `media/{asset_id}/{source_id}/hls/`.

---

## Callback timeout and failures

If the worker gets **cURL error 28: Connection timed out** when calling the CDN callback, the pipeline still **completes** (artifact is ready). The worker logs the failure and the request is marked **completed** so you can use "Fetch artifact from worker" on the CDN. Set **CDN_CALLBACK_TIMEOUT** (default 90) and **CDN_CALLBACK_CONNECT_TIMEOUT** (default 15) in the worker env if the CDN or proxy is slow to respond.

---

## Quick check

- **Worker:** `WORKER_API_TOKEN` is set (and used by the worker to require Bearer on `/api/v1/artifacts/{token}`).
- **CDN:** `CDN_LARAVEL_WORKER_API_TOKEN` = that same value, and `CDN_LARAVEL_WORKER_API_URL` points at the worker. Then the CDN can fetch the artifact URL and the worker will accept the request.
