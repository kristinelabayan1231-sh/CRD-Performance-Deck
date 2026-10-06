#!/bin/sh
# Container start: load secrets, prepare Laravel, migrate, then serve.
set -e

cd /app

# Render "Secret File" named .env (Environment → Secret Files). Dashboard
# environment variables still win over values in this file.
if [ -f /etc/secrets/.env ]; then
    cp /etc/secrets/.env /app/.env
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

# Hourly lead + Pancake syncs (routes/console.php). Render's free plan sleeps
# when idle, which pauses this until the next visit.
php artisan schedule:work &

# Render sets PORT. php-server sends every request that isn't a file to public/index.php.
exec frankenphp php-server --root /app/public --listen ":${PORT:-8080}"
