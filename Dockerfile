# syntax=docker/dockerfile:1
FROM php:8.3-cli AS base

## Install SQLite dev libs and build pdo_sqlite once in the image
RUN set -e; \
    apt-get update && apt-get install -y --no-install-recommends \
        build-essential pkg-config libsqlite3-dev sqlite3 git unzip \
    && docker-php-ext-configure pdo_sqlite \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
    && git config --system --add safe.directory /var/www \
    && rm -rf /var/lib/apt/lists/*

## Add Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

## Pre-install dependencies to leverage Docker layer caching (useful for CI or non-mounted runs).
## In dev with a bind mount, these will be overshadowed by the host.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress
