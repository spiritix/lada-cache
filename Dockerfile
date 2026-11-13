# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libsqlite3-dev libpq-dev git unzip; \
    docker-php-ext-install -j"$(nproc)" pdo_sqlite pdo_mysql pdo_pgsql pcntl; \
    pecl install redis apcu; \
    docker-php-ext-enable redis apcu; \
    printf 'apc.enable_cli=1\nmemory_limit=1G\n' > /usr/local/etc/php/conf.d/lada-cache.ini; \
    git config --system --add safe.directory /var/www; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www
