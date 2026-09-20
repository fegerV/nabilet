# NABILET Core — production image
#
# Two stages share one base so the PHP extensions are built exactly once and the
# runtime cannot drift from the build (a classic cause of "works in CI, fails in
# prod": extensions present when the dependencies were resolved, missing after).
#
# NOT YET BUILT ANYWHERE. This image has never been assembled: outbound Docker
# pulls from this machine fail with `x509: certificate has expired or is not yet
# valid` (the proxy intercepts TLS), so no base image can be fetched locally. CI
# lints it (hadolint) and validates the compose file; the first real build will
# happen on a runner, or wherever you have registry access.
#
# See docker-compose.yml for how the pieces fit together.

FROM php:8.3-fpm-alpine AS base

# Runtime libraries first: `lib*` packages are kept, `-dev` ones exist only to
# compile the extensions and are removed afterwards so the image stays small.
RUN apk add --no-cache \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        icu-libs \
        bash \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        gd \
        opcache \
        pcntl \
    && apk del .build-deps

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-nabilet-opcache.ini

# ── build stage ──────────────────────────────────────────────────────────────
# Dependencies are resolved here, inside a php:8.3 image, so the platform Composer
# checks against is the platform that will actually run the code. Resolving in a
# `composer:2` container instead risks pinning versions against a different PHP.
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /app

# No composer.lock is committed: it cannot be generated without network access to
# Packagist, so the fallback to `update` is what lets the image build at all
# today. Committing the lock file is the fix — until then builds are not
# reproducible, and `install` is preferred the moment it exists.
#
# The tree is copied before resolving rather than copying composer.json first.
# The usual trick (`COPY composer.json composer.lock ./`) keeps the dependency
# layer cacheable but FAILS the build outright when the lock is absent — a
# wildcard COPY with no match is an error, not a no-op. Correctness wins here;
# the layer can be split once composer.lock is committed.
COPY . .

RUN if [ -f composer.lock ]; then \
        composer install --no-dev --prefer-dist --no-scripts; \
    else \
        composer update --no-dev --prefer-dist --no-scripts; \
    fi \
    # --no-scripts: `post-autoload-dump` runs `artisan package:discover`, which
    # needs a bootable application and a database connection, and neither exists
    # at build time.
    && composer dump-autoload --optimize --no-dev --no-scripts

# ── runtime ──────────────────────────────────────────────────────────────────
FROM base

ENV APP_ENV=production \
    APP_DEBUG=false

WORKDIR /var/www/html

COPY --from=build /app ./

# storage/ and bootstrap/cache/ must be writable by the FPM pool user; everything
# else stays root-owned so a compromised PHP process cannot rewrite the codebase.
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
