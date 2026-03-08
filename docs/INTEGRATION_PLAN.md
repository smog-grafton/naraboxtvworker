# NaraboxTV Worker — Integration Plan

## 1. Verified active worker project

- **Path:** `/Applications/XAMPP/xamppfiles/htdocs/file-server-worker`
- **Repo:** https://github.com/smog-grafton/naraboxtvworker
- **narabox-data-pipe** is a different project (data-pipe repo); not the media worker.

## 2. Worker codebase (current)

- Laravel app with Horizon, Redis default queue, queues: `transcode`, `probe`, `sync`.
- **Config:** `config/media_worker.php` — `temp_dir`, `ffmpeg_bin`, `ffprobe_bin`, `queues`, `cdn`, `portal` (all from env).
- **Env (from .env.example):** `APP_*`, `DB_*`, `QUEUE_CONNECTION`, `REDIS_*`, `CDN_API_BASE_URL`, `CDN_API_TOKEN`, `PORTAL_API_BASE_URL`, `PORTAL_API_TOKEN`, `WORKER_TEMP_DIR`, `FFMPEG_BIN`, `FFPROBE_BIN`, `TRANSCODE_QUEUE`, `PROBE_QUEUE`, `SYNC_QUEUE`, `HORIZON_*`.
- **Existing:** `RunFfmpegHealthcheckJob`, `worker:dispatch-healthcheck`, `ffmpeg:test`. No DB models for processing yet; only default migrations (users, cache, jobs).

## 3. CDN (naraboxtv-cdn)

- **DB:** `media_assets` (uuid id), `media_sources` (media_asset_id, source_type, source_url, storage_path, status, optimize_status, optimized_path, hls_master_path, qualities_json, etc.).
- **Storage:** `storage/app/public/media/{uuid}/{sourceId}/` — original and `*_play.mp4`; `media/{uuid}/{sourceId}/hls/` for HLS.
- **Flow:** Import (API) creates asset + source, then `ImportRemoteMediaSourceJob` (download) → source becomes `ready` → `queuePlaybackProcessing()` chains `OptimizeMp4FaststartJob` and `GenerateHlsVariantsJob` on queue `optimization` (run on CDN today).
- **API:** Bearer token via `MediaApiToken` (token_hash). Routes under `v1` with `cdn.token`: import, upload, showAsset, playback, showSource, lookupSource, destroySource.
- **Worker hook:** `queuePythonWorkerImport()` exists; can be extended for Laravel worker (submit job to worker API or Redis).

## 4. Portal (naraboxt-lara)

- **DB:** `video_sources` (sourceable_type/id, type, url, file_path, quality, format, metadata with cdn_asset_id, cdn_source_id, source_role).
- **Flow:** VideoSource created/updated → `VideoSourceDerivationService::ensureDerivedSourcesForCdnUrl()` → `CdnUrlDerivationService::deriveFromCdnUrl()` parses CDN URLs and creates sibling VideoSources (mp4_play, hls_master, original).
- **Playback:** Player API uses CDN URLs; Portal does not store files, only references CDN.

## 5. Architectural decisions

- **Worker owns:** processing lifecycle (request → download → probe → transcode → HLS → upload/callback → sync). CDN remains source of truth for stored public media; worker is processor/orchestrator.
- **Auth:** Worker API protected by Bearer token (`WORKER_API_TOKEN`). CDN/Portal call worker with this token. Worker calls CDN/Portal with existing `CDN_API_TOKEN` and `PORTAL_API_TOKEN`.
- **Contract:** CDN (or Portal) submits a “processing request” to worker (asset id, source id, source URL, callback hints). Worker creates `ProcessingRequest`, runs pipeline jobs, then calls CDN API to report results (and optionally notifies Portal).
- **DB (worker):** New tables: `processing_requests`, `processing_attempts`, `callback_logs`, `sync_logs`. Optional: `worker_settings` for overrides (or keep all in config).

## 6. Implementation phases

- **Phase 1:** Domain model (migrations, models), enums, services (skeleton), job classes (skeleton), config (auth), internal API (submit, status, retry) + auth.
- **Phase 2:** Filament admin UI (dashboard, Processing Requests, Attempts, Callback Logs, Settings).
- **Phase 3:** Real CDN/Portal integration (worker calls CDN to update source/artifact; CDN optionally pushes work to worker instead of running optimization locally).

## 7. Env / config to use (no new path or env names beyond one token)

- Keep using: `APP_*`, `DB_*`, `REDIS_*`, `CDN_API_BASE_URL`, `CDN_API_TOKEN`, `PORTAL_API_BASE_URL`, `PORTAL_API_TOKEN`, `WORKER_TEMP_DIR`, `FFMPEG_BIN`, `FFPROBE_BIN`, `TRANSCODE_QUEUE`, `PROBE_QUEUE`, `SYNC_QUEUE`, `HORIZON_*`.
- Add (documented): `WORKER_API_TOKEN` — Bearer token for CDN/Portal to call worker API. Placeholder in .env.example.
