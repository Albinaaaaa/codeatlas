# syntax=docker/dockerfile:1

FROM php:8.4-fpm-bookworm AS base

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        intl \
        zip \
        pcntl \
        bcmath \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/codeatlas.ini


FROM base AS development

ENV APP_ENV=local

CMD ["php-fpm"]
