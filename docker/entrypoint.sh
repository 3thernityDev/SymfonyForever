#!/bin/sh
set -e

mkdir -p /data /data/sessions /app/var/cache /app/var/log /app/var/map
chmod -R 775 /data /app/var

if [ ! -f /app/var/map/reunion_terrain.bin ]; then
    php bin/console app:world:generate-map --no-interaction
fi

# Run schema update & seeding only on fresh DB or new deployment (keeps machine wakeups < 1.5s!)
CURRENT_VER="$(cat /app/.schema_version 2>/dev/null || echo 'init')"
APPLIED_VER="$(cat /data/.schema_version 2>/dev/null || echo '')"
if [ ! -f /data/symfonyforever.db ] || [ "$CURRENT_VER" != "$APPLIED_VER" ]; then
    php bin/console doctrine:schema:update --force --no-interaction
    php bin/console app:world:seed --no-interaction
    echo "$CURRENT_VER" > /data/.schema_version
fi

exec frankenphp run --config /etc/caddy/Caddyfile
