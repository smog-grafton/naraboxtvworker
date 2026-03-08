#!/bin/sh
set -e
# Coolify/Traefik route to port 3000: run Laravel web server so Filament and API are reachable.
php artisan serve --host=0.0.0.0 --port=3000 &
# Horizon as main process (receives signals, keeps container running)
exec php artisan horizon
