#!/bin/sh
set -e

# Coolify injects env vars at runtime; the image has no .env (excluded by .dockerignore).
# Create .env from environment so Laravel and artisan see APP_KEY, Redis, DB, etc.
if [ ! -f /app/.env ]; then
    for key in APP_NAME APP_ENV APP_KEY APP_DEBUG APP_URL \
        LOG_CHANNEL LOG_STACK LOG_LEVEL \
        DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SSL_MODE DB_SOCKET DB_CHARSET DB_COLLATION \
        QUEUE_CONNECTION CACHE_STORE SESSION_DRIVER SESSION_LIFETIME \
        REDIS_CLIENT REDIS_HOST REDIS_PASSWORD REDIS_PORT \
        CDN_API_BASE_URL CDN_API_TOKEN PORTAL_API_BASE_URL PORTAL_API_TOKEN \
        WORKER_API_TOKEN WORKER_TEMP_DIR FFMPEG_BIN FFPROBE_BIN \
        TRANSCODE_QUEUE PROBE_QUEUE SYNC_QUEUE \
        HORIZON_PREFIX HORIZON_TRANSCODE_PROCESSES HORIZON_TRANSCODE_TIMEOUT \
        HORIZON_PROBE_PROCESSES HORIZON_SYNC_PROCESSES; do
        val="$(printenv "$key" 2>/dev/null || true)"
        [ -z "$val" ] && continue
        printf '%s=%s\n' "$key" "$val" >> /app/.env
    done
    # Ensure APP_KEY is present (required; empty causes 500)
    if ! grep -q '^APP_KEY=' /app/.env 2>/dev/null; then
        echo 'APP_KEY=' >> /app/.env
    fi
fi

# Coolify/Traefik route to port 3000: run Laravel web server so Filament and API are reachable.
php artisan serve --host=0.0.0.0 --port=3000 &
# Horizon as main process (receives signals, keeps container running)
exec php artisan horizon
