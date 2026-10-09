#!/bin/sh
# Container start: load secrets, prepare Laravel, migrate, then serve.
set -e

cd /app

# Render "Secret File" named .env (Environment → Secret Files). Dashboard
# environment variables still win over values in this file.
if [ -f /etc/secrets/.env ]; then
    cp /etc/secrets/.env /app/.env
fi

# Without database settings Laravel falls back to a SQLite file inside the
# container, which is wiped on every restart or deploy. Say so loudly.
if [ -z "$DB_URL" ] && [ -z "$DB_HOST" ] && { [ -z "$DB_CONNECTION" ] || [ "$DB_CONNECTION" = "sqlite" ]; }; then
    echo "WARNING: no DB_URL/DB_HOST set: using a temporary SQLite file that is erased on every restart." >&2
    echo "WARNING: set DB_CONNECTION=pgsql and DB_URL (Render Postgres → Internal Database URL)." >&2
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

# Lead (hourly) + Pancake (every 10 minutes) syncs (routes/console.php). Render's free plan sleeps
# when idle, which pauses this until the next visit.
php artisan schedule:work &

# Render sets PORT. php-server sends every request that isn't a file to public/index.php.
exec frankenphp php-server --root /app/public --listen ":${PORT:-8080}"
