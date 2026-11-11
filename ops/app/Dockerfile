# Chapter 25, Figure 25-7 (`ai-warm: image: laravel-app`) and Topic 8: the Laravel app as a container, for the base stack of the book's Compose layout: app, reverb, horizon and ai-warm all run this one image (ops/compose/app.yml).
# PHP 8.3 and Composer are PINNED, not `latest`. No Node, no build step (Chapter 18). Horizon needs ext-pcntl and ext-posix; Postgres needs pdo_pgsql; the DOCX parser (phpoffice/phpword, Chapter 16) needs gd and zip; Redis is reached through Predis, so no phpredis.
#
#   docker build -f ops/app/Dockerfile -t ledgerly/app:ch25 .
FROM composer:2.10.3 AS composer

FROM php:8.3.35-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql pcntl gd zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
