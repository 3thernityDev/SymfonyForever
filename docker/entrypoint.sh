#!/bin/sh
set -e

mkdir -p /data /app/var/cache /app/var/log /app/var/map
chmod -R 775 /data /app/var

if [ ! -f /app/var/map/reunion_terrain.bin ]; then
    php bin/console app:world:generate-map --no-interaction
fi

php bin/console doctrine:schema:update --force --no-interaction
php bin/console app:world:seed --no-interaction

exec frankenphp run --config /etc/caddy/Caddyfile
