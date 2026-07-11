#!/bin/sh
set -e

# Render assigns a dynamic $PORT at runtime; Apache defaults to 80.
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

# Render's free tier has no Pre-Deploy Command hook (paid-plan only), so
# migrations (and the seeder, which upserts the allowed-emails admin row)
# run here instead, on every container start. Both are idempotent —
# "migrate --force" only applies pending migrations, and the seeder uses
# updateOrCreate — so this is safe to repeat across restarts and
# free-tier spin-down/spin-up cycles.
php artisan migrate --force
php artisan db:seed --force

# Render's free tier has no separate Cron Job/Background Worker service, so
# the Laravel scheduler (routes/console.php — pancake:sync-cra-stats,
# pancake:sync-customer-dashboard) runs as a background loop in this same
# container rather than via system cron. schedule:work wakes up once a
# minute and only actually invokes a command when its own schedule says to.
php artisan schedule:work &

exec apache2-foreground
