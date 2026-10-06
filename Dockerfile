# CRD Performance Deck: production image for Render (or any Docker host).
# FrankenPHP serves public/ on $PORT; the entrypoint runs migrations and the scheduler.

# 1. PHP dependencies (no dev packages)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# 2. Front-end assets (Tailwind scans vendor's pagination views, so vendor is copied in)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN npm run build

# 3. Runtime
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql pdo_mysql intl zip bcmath opcache pcntl

# The image gives frankenphp the cap_net_bind_service file capability (for ports
# below 1024). Hosts like Render refuse to run binaries with file capabilities
# ("exec: frankenphp: Operation not permitted"); we listen on $PORT, so drop it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcap2-bin \
    && setcap -r "$(command -v frankenphp)" \
    && apt-get purge -y libcap2-bin \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 8080

ENTRYPOINT ["docker/entrypoint.sh"]
