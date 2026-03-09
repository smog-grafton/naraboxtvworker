#!/bin/sh
set -e

# Rely on Coolify (or runtime) environment variables only. No .env file is created:
# the image has no .env (.dockerignore), and the container runs as www-data which
# cannot write to /app. Laravel reads config from getenv() when .env is missing.

# Coolify/Traefik route to port 3000: run Laravel web server so Filament and API are reachable.
php artisan serve --host=0.0.0.0 --port=3000 &
# Horizon as main process (receives signals, keeps container running).
# Use exec so Horizon gets PID 1 and receives SIGTERM for graceful shutdown.
exec php artisan horizon
