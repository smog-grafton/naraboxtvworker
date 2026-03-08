# ---------------------------------------------------------------------------
# NaraboxTV File Server Worker - Production Dockerfile
# ---------------------------------------------------------------------------
# Uses a PHP 8.4 image with extensions pre-installed (intl, redis, pdo_mysql,
# bcmath, zip, exif, pcntl) so the build finishes quickly on Coolify (no
# compiling PHP extensions). FFmpeg added for transcoding.
# Matches composer.lock (Symfony 8 / Carbon 3.11 require PHP 8.4).

# ByJG PHP 8.4 CLI: Alpine-based, 45+ extensions (intl, redis, pdo_mysql, etc.)
# Pin to monthly tag for production; see https://opensource.byjg.com/docs/devops/docker-php/tagging
FROM byjg/php:8.4-cli-2025.03 AS base

# FFmpeg for transcoding (Alpine)
RUN apk add --no-cache ffmpeg

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

# Dependencies first (better layer cache)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

# Ensure storage/cache writable; create www-data if missing (Alpine/BusyBox)
# Alpine uses addgroup/adduser: create group then user (uid 82 = standard www-data)
RUN addgroup -g 82 -S www-data 2>/dev/null || true \
    && adduser -u 82 -D -S -G www-data -H www-data 2>/dev/null || true \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

RUN chmod +x /app/docker-entrypoint.sh

EXPOSE 3000
USER www-data

# Web (Filament, API) on 0.0.0.0:3000 + Horizon
CMD ["./docker-entrypoint.sh"]
