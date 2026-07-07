# ---- Composer dependencies ----
# composer.lock is locked against PHP >= 8.4.1 (Symfony 8.1.x components) —
# run composer under that PHP version, or "composer install" fails the
# platform check. (The standalone composer:2 image bundles its own,
# lower PHP — so it fails this same check; copying just the composer
# binary onto a php:8.4 base sidesteps that.)
FROM php:8.4-cli AS vendor
RUN apt-get update && apt-get install -y unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# ---- Frontend assets ----
FROM node:20-alpine AS assets
WORKDIR /app
COPY . .
RUN npm ci && npm run build

# ---- Runtime image ----
FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
        libpq-dev \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql mbstring bcmath curl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && sed -ri -e "s!/var/www/!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY --from=vendor /app .
COPY --from=assets /app/public/build ./public/build

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
