ARG PHP_VERSION=8.4
ARG ERIN_BUILD_SHA
ARG ERIN_GOVERNANCE_TRUST_ROOT_SHA256

FROM php:${PHP_VERSION}-fpm-bookworm@sha256:66a10c4b32b2f5886c32a4fb23c6cc3e727661e6a929c241befb335aaee0290e AS php-base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH="/app/vendor/bin:${PATH}"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-erin.ini
COPY docker/production/php.ini /usr/local/etc/php/conf.d/100-erin-production.ini

WORKDIR /app

FROM php-base AS php-builder

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

COPY . .
RUN php artisan package:discover --ansi \
    && php artisan wayfinder:generate --with-form

FROM node:22-bookworm-slim@sha256:6c74791e557ce11fc957704f6d4fe134a7bc8d6f5ca4403205b2966bd488f6b3 AS frontend-builder

ARG VITE_APP_NAME=Erin
ARG VITE_BROADCAST_CONNECTION=reverb
ARG VITE_REVERB_PUBLIC_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
ARG VITE_LIVEKIT_URL

ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_BROADCAST_CONNECTION=${VITE_BROADCAST_CONNECTION} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME} \
    VITE_LIVEKIT_URL=${VITE_LIVEKIT_URL}

WORKDIR /app
COPY --from=php-builder /app /app
RUN npm ci --no-audit --no-fund \
    && VITE_REVERB_APP_KEY="${VITE_REVERB_PUBLIC_KEY}" npm run build

FROM php-base AS php

ARG ERIN_BUILD_SHA
ARG ERIN_GOVERNANCE_TRUST_ROOT_SHA256

ENV APP_ENV=production \
    APP_DEBUG=false \
    ERIN_BUILD_SHA=${ERIN_BUILD_SHA} \
    LOG_CHANNEL=stderr

LABEL org.opencontainers.image.revision="${ERIN_BUILD_SHA}"

COPY --from=php-builder --chown=www-data:www-data /app /app
COPY --from=frontend-builder --chown=www-data:www-data /app/public/build /app/public/build
COPY --chown=www-data:www-data docker/production/entrypoint.sh /usr/local/bin/erin-entrypoint

RUN printf '%s' "${ERIN_BUILD_SHA}" | grep -Eq '^[0-9a-f]{40}$' \
    && printf '%s' "${ERIN_GOVERNANCE_TRUST_ROOT_SHA256}" | grep -Eq '^[0-9a-f]{64}$' \
    && printf '%s\n' "${ERIN_BUILD_SHA}" > /app/.erin-build-sha \
    && printf '%s\n' "${ERIN_GOVERNANCE_TRUST_ROOT_SHA256}" > /app/.erin-governance-trust-root-sha256 \
    && chmod 0444 /app/.erin-build-sha \
    && chmod 0444 /app/.erin-governance-trust-root-sha256 \
    && chmod +x /usr/local/bin/erin-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

ENTRYPOINT ["erin-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]

FROM nginx:1.28-alpine@sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236 AS nginx

ARG ERIN_BUILD_SHA
ARG ERIN_GOVERNANCE_TRUST_ROOT_SHA256

LABEL org.opencontainers.image.revision="${ERIN_BUILD_SHA}"

COPY docker/production/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=php /app/public /app/public

RUN printf '%s' "${ERIN_BUILD_SHA}" | grep -Eq '^[0-9a-f]{40}$' \
    && printf '%s' "${ERIN_GOVERNANCE_TRUST_ROOT_SHA256}" | grep -Eq '^[0-9a-f]{64}$'
