# ---------------------------------------------------------------------------
# NaraboxTV File Server Worker - Production Dockerfile
# ---------------------------------------------------------------------------
# PHP 8.4 CLI-based image for queue workers + Horizon. FFmpeg installed.
# Matches composer.lock (Symfony 8 / Carbon 3.11 require PHP 8.4).
# Suitable for Coolify Dockerfile deployment.

FROM php:8.4-cli-bookworm AS base

# Install system deps + FFmpeg (libicu-dev required for intl - Filament)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions for Laravel + Filament (intl required by filament/support)
RUN docker-php-ext-install -j$(nproc) \
    bcmath \
    exif \
    intl \
    pcntl \
    zip \
    pdo_mysql

# Redis extension (for queue)
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# App files
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

# Ensure storage/cache writable
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# Entrypoint: serve web on 3000 (for Coolify proxy) + Horizon
RUN chmod +x /app/docker-entrypoint.sh

# Port Coolify/Traefik forwards to (loadbalancer.server.port=3000)
EXPOSE 3000

USER www-data

# Web (Filament, API) on 0.0.0.0:3000 + Horizon
CMD ["./docker-entrypoint.sh"]
