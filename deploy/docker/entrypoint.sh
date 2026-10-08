#!/bin/sh
# Shared by the app, worker and scheduler containers. Caches are built here
# rather than in the image because they freeze the environment, which only
# exists once the container starts. Only the app container migrates
# (RUN_MIGRATIONS=true); the others wait for it to report healthy.
set -e

cd /var/www/html

# Artisan writes cache files, which PHP-FPM's www-data must be able to read
# and replace, so it never runs as root.
artisan() {
    if [ "$(id -u)" = "0" ]; then
        su-exec www-data php artisan "$@"
    else
        php artisan "$@"
    fi
}

artisan optimize

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    artisan migrate --force --isolated
fi

exec "$@"
