FROM php:8.3-fpm

# nginx + supervisor so this is a single, self-contained container (no
# separate services/network needed to run it). pdo_sqlite/gd are the only PHP
# extensions this app needs beyond what the base image already ships (curl,
# fileinfo). gd's -dev packages (headers/static libs) are build-time only and
# safe to purge afterward, but gd itself links dynamically against their
# runtime libraries (e.g. libpng16.so.16) - purging with --auto-remove was
# sweeping those runtime .so files away too as "orphaned" dependencies of the
# -dev packages, breaking gd (and thumbnail generation) at runtime. Dropping
# --auto-remove here still removes the named -dev packages, just without
# cascading into their still-needed runtime dependencies.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor \
        libsqlite3-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite gd \
    && apt-get purge -y libsqlite3-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

WORKDIR /var/www/html
COPY . /var/www/html

COPY docker/nginx.conf /etc/nginx/sites-enabled/plex-art-manager.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && rm -rf /var/www/html/docker \
    && mkdir -p /var/www/html/data /var/www/html/cache/thumbs

# Overridden at `docker run`/compose time to match whatever uid/gid should own
# files on your media library's mount - see entrypoint.sh. Default 1000:1000
# matches the first non-root user on most Linux distros.
ENV PUID=1000
ENV PGID=1000

EXPOSE 80
# Named volumes or bind mounts here keep the database/thumbnail cache across
# container rebuilds - point your actual movie library at a separate mount
# (e.g. /movies), configured via docker-compose.yml.
VOLUME ["/var/www/html/data", "/var/www/html/cache"]

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
