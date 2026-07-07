#!/bin/sh
set -e

# Render assigns a dynamic $PORT at runtime; Apache defaults to 80.
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

# Render's free tier has no Pre-Deploy Command hook (paid-plan only), so
# migrations run here instead, on every container start. "migrate --force"
# only applies pending migrations, so this is safe to repeat across restarts
# and free-tier spin-down/spin-up cycles.
php artisan migrate --force

exec apache2-foreground
