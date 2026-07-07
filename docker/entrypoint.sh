#!/bin/sh
set -e

# Render assigns a dynamic $PORT at runtime; Apache defaults to 80.
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

exec apache2-foreground
