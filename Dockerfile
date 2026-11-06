# syntax=docker/dockerfile:1
FROM php:8.4-cli AS base

RUN set -e; \
    apt-get update && apt-get install -y --no-install-recommends \
        build-essential pkg-config libsqlite3-dev libpq-dev sqlite3 git unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite pdo_mysql pdo_pgsql pcntl \
    && pecl install redis apcu \
    && docker-php-ext-enable redis apcu \
    && echo 'apc.enable_cli=1' > /usr/local/etc/php/conf.d/apcu-cli.ini \
    && git config --system --add safe.directory /var/www \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts
