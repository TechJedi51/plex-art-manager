#!/bin/bash
set -e

PUID=${PUID:-1000}
PGID=${PGID:-1000}

# Adjust www-data's uid/gid to match PUID/PGID rather than hardcoding numeric
# ids into php-fpm's pool config - keeps the pool config itself portable, and
# lets whoever runs this container control file ownership on their media
# library's mount via plain environment variables (same convention used by
# LinuxServer.io and similar self-hosted images).
CURRENT_UID=$(id -u www-data)
CURRENT_GID=$(id -g www-data)
if [ "$CURRENT_GID" != "$PGID" ]; then
    groupmod -o -g "$PGID" www-data
fi
if [ "$CURRENT_UID" != "$PUID" ]; then
    usermod -o -u "$PUID" www-data
fi

# data/ and cache/ are separate volumes and start out root-owned the first
# time Docker creates them - fix that before php-fpm's workers (running as
# www-data, now PUID:PGID) try to write to them. Cheap no-op once correct.
mkdir -p /var/www/html/data /var/www/html/cache/thumbs
chown -R www-data:www-data /var/www/html/data /var/www/html/cache

exec "$@"
