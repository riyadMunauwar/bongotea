# syntax=docker/dockerfile:1

# Production images for docker-compose.yml. Two targets: `app` (PHP-FPM and
# nginx, also run as the queue worker and the scheduler) and `ssr` (Node and
# the server-side rendering bundle, nothing else).

FROM php:8.4-fpm-alpine AS php

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions bcmath exif gd intl opcache pcntl pdo_pgsql zip \
    && apk add --no-cache nginx su-exec supervisor

WORKDIR /var/www/html


# Composer dependencies, then the application, so a code change does not
# reinstall vendor/. The dump runs the post-autoload scripts, which publish
# Filament's assets into public/.
FROM php AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --no-interaction


# The browser assets and the SSR bundle. The Filament theme imports CSS out
# of vendor/, so the application comes from the stage above.
FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY --from=vendor /var/www/html ./
RUN npm run build


FROM php AS app

COPY deploy/docker/php.ini $PHP_INI_DIR/conf.d/zz-bongotea.ini
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-bongotea.conf
COPY deploy/docker/nginx.conf /etc/nginx/http.d/default.conf
COPY deploy/docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=755 deploy/docker/entrypoint.sh /usr/local/bin/entrypoint

COPY --from=vendor --chown=www-data:www-data /var/www/html ./
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY --from=assets --chown=www-data:www-data /app/bootstrap/ssr ./bootstrap/ssr

# The public storage disk is a named volume mounted at storage/app/private;
# the directory is created here so the volume starts out owned by www-data.
# The link comes from config/filesystems.php's `links`, made relative so it
# means the same thing in every container.
RUN mkdir -p storage/app/private storage/app/staging \
    && php artisan storage:link --relative \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]


# Built with `ssr.noExternal`, so the bundle carries React and Inertia inside
# it and this image needs no node_modules.
FROM node:24-alpine AS ssr

WORKDIR /app

COPY --from=assets --chown=node:node /app/bootstrap/ssr ./bootstrap/ssr

USER node
EXPOSE 13714

CMD ["node", "bootstrap/ssr/ssr.js"]
