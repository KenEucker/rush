# syntax=docker/dockerfile:1.7

FROM composer:2.8.4 AS vendor

WORKDIR /app

COPY server/composer.json server/composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

COPY server ./

RUN composer dump-autoload --optimize --no-interaction

FROM php:8.3.14-fpm-alpine3.20 AS runtime

WORKDIR /var/www/html

RUN apk add --no-cache icu-libs libpq libzip oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev postgresql-dev \
    && docker-php-ext-install bcmath intl mbstring opcache pcntl pdo_pgsql zip \
    && apk del .build-deps

COPY docker/server/php.ini /usr/local/etc/php/conf.d/rush.ini
COPY docker/server/entrypoint.sh /usr/local/bin/rush-entrypoint
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

RUN chmod +x /usr/local/bin/rush-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["sh", "/usr/local/bin/rush-entrypoint"]
CMD ["php-fpm", "-F"]
