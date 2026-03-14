# Debugging "Error writing trailer: No such file or directory"

The worker saves temp files under **WORKER_TEMP_DIR** (default: `storage_path('app/worker-temp')` → e.g. `/app/storage/app/worker-temp` in Docker). Each request gets a subdir: `{WORKER_TEMP_DIR}/{external_id}/` with `source.{ext}`, `optimized.mp4`, `hls/`, `hls.zip`.

If you see **"Temp dir exists: NO"** when running `worker:debug-temp`, the base dir was never created (or was removed). The pipeline job now creates the request dir at the very start; if it still can't write, set **WORKER_TEMP_DIR** to a path that is writable (e.g. `/tmp/worker-temp` in Coolify).

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

### 3. Fix: set a writable temp dir in Coolify (recommended)

If you see **"Temp dir exists: NO"** or faststart still fails with "No such file or directory", set in Coolify **Environment**:

- **WORKER_TEMP_DIR** = **`/tmp/worker-temp`**

Then redeploy or restart the app. The worker will use `/tmp/worker-temp`; `/tmp` is writable in most containers and avoids issues with `storage/app` being read-only or missing. The pipeline creates the base and per-request dirs at job start; with this env set, they will be under `/tmp/worker-temp/{external_id}/`.
