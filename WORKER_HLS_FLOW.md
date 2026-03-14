# Worker HLS flow (pull-based)

## Overview

The worker generates HLS from a source URL (e.g. CDN download URL), validates outputs, packages the HLS folder into a ZIP, and exposes a **temporary token-based download URL**. The CDN pulls that ZIP and installs it locally; the worker does not push files to the CDN.

## Current processing flow

1. **Submit** – CDN (or admin) POSTs to `POST /api/v1/processing/submit` with `cdn_asset_id`, `cdn_source_id`, `source_url` (e.g. CDN MP4 download URL), `original_filename`, optional `callback_url` / `portal_sync_hint` / `payload`.
2. **Download** – Worker fetches `source_url` into temp storage. The local filename uses **CDN-style resolution** (`RemoteFilenameResolver`): extension is taken from query params (e.g. `?file=path/Title.mp4`) so script paths like `downloadmp4.php` never become the file extension. File is saved as `source.{ext}` (e.g. `source.mp4`) under a per-request subdir. Timeouts/retries: `config/media_worker.download`.
3. **Probe** – FFprobe extracts duration, size, codec, resolution.
4. **Faststart** – FFmpeg produces `optimized.mp4` with `-movflags +faststart`.
5. **HLS** – `FfmpegTranscodeService::generateHls()` builds profiles (1080p/720p/480p or source fallback), validates variant playlists and segments, writes `master.m3u8`.
6. **Artifact** – HLS directory is zipped (flat layout: `master.m3u8`, `{profile}/index.m3u8`, `{profile}/segment_*.ts`). An `HlsArtifact` record is created with `download_token`, `download_expires_at` (TTL from `media_worker.artifacts.ttl_minutes`).
7. **Callback** – Worker POSTs to CDN `/api/v1/media/worker/callback` with `status`, `artifact_download_url`, `artifact_expires_at`, `quality_status`, `qualities_json`, `external_id`.
8. **Cleanup** – Temp files are cleared after the job; artifacts can be expired by a scheduled command (e.g. `worker:cleanup-artifacts`).

## Key tables

- **processing_requests** – Incoming job (external_id, cdn_asset_id, cdn_source_id, source_url, status, artifact_paths, etc.).
- **hls_artifacts** – One per request: status (`packaging`|`artifact_ready`|`fetched_by_cdn`|`expired`|`failed`), download_token, download_expires_at, zip_path, qualities_json, quality_status.

## Key jobs and services

- **ProcessMediaPipelineJob** – Orchestrates download → probe → faststart → HLS → zip → artifact → callback.
- **MediaDownloadService** – HTTP download with retries/timeouts.
- **FfmpegTranscodeService** – probe, faststart, generateHls (with variant and master validation).
- **TempFileService** – Per-request subdir under `config('media_worker.temp_dir')`: `{temp_dir}/{external_id}/` holds `source.{ext}`, `optimized.mp4`, `hls/`, `hls.zip`. Avoids "Error writing trailer: No such file or directory" and keeps cleanup simple.
- **RemoteFilenameResolver** – Resolves video extension (and basename) from URL query/path so we never use `.php` or other non-video extensions (aligned with naraboxtv-cdn).

## Endpoints

- `GET /api/v1/artifacts/{token}` – Download HLS ZIP (token from `HlsArtifact.download_token`). Requires `worker.api` (Bearer) auth.
- `POST /api/v1/artifacts/{externalId}/ack` – Mark artifact as `fetched_by_cdn` after CDN has installed the ZIP.

## Config / env

- `WORKER_TEMP_DIR` – Temp root for request files.
- `WORKER_DOWNLOAD_TIMEOUT`, `WORKER_DOWNLOAD_CONNECT_TIMEOUT`, `WORKER_DOWNLOAD_RETRY_TIMES`, `WORKER_DOWNLOAD_RETRY_SLEEP_MS` – Download behaviour.
- `WORKER_ARTIFACTS_ENABLED`, `WORKER_ARTIFACTS_TTL_MINUTES`, `WORKER_ARTIFACTS_CLEANUP_BATCH_SIZE` – Artifact TTL and cleanup.
- `CDN_API_BASE_URL`, `CDN_API_TOKEN` – Used to call CDN callback.
- `WORKER_API_TOKEN` – Incoming API auth (CDN uses this to fetch artifacts and call ack).

## Migrations

- `2026_03_09_120500_create_hls_artifacts_table` – Creates `hls_artifacts` (processing_request_id, external_id, status, quality_status, qualities_json, hls_dir, zip_path, zip_size_bytes, download_token, download_expires_at, last_fetched_at, failure_reason).

## Filament

- **ProcessingRequest** – Table shows artifact status and expiry; “Copy artifact URL” action when artifact is ready. View page shows HLS artifact status, quality status, expiry, ZIP size.
