# Debugging "Error writing trailer: No such file or directory"

The worker saves temp files under **WORKER_TEMP_DIR** (default: `storage_path('app/worker-temp')` → e.g. `/app/storage/app/worker-temp` in Docker). Each request gets a subdir: `{WORKER_TEMP_DIR}/{external_id}/` with `source.{ext}`, `optimized.mp4`, `hls/`, `hls.zip`.

If faststart fails with "No such file or directory", the temp dir is often missing or not writable (e.g. read-only filesystem in the container).

## Commands to run in Coolify terminal (Execute command)

Run these **inside the worker app container** (Coolify → your app → Execute command / Terminal).

### 1. See where files go and check writability

```bash
php artisan worker:debug-temp
```

With a specific request UUID:

```bash
php artisan worker:debug-temp 62c15d03-55bb-4006-9fd0-778978a4e7c0
```

This prints the resolved temp dir, paths for that request, and tries a test write.

### 2. Check env and disk (if you have a shell)

```bash
env | grep -E 'WORKER_TEMP|STORAGE'
```

```bash
df -h
```

```bash
ls -la storage/app/
```

```bash
whoami
```

### 3. Fix: set a writable temp dir in Coolify

If `storage/app/worker-temp` is not writable (e.g. in Docker the app volume might be read-only), set in Coolify **Environment**:

- **WORKER_TEMP_DIR** = `/tmp/worker-temp`

Then redeploy or restart the app so the worker uses `/tmp/worker-temp`. Ensure the container has write access to `/tmp`.
