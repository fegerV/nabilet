# NABILET Core — production image
#
# Two stages share one base so the PHP extensions are built exactly once and the
# runtime cannot drift from the build (a classic cause of "works in CI, fails in
# prod": extensions present when the dependencies were resolved, missing after).
#
# Built for real by CI: the `Container files` job runs `docker build` on every push,
# so this file is exercised rather than only linted. It was written on a machine where
# outbound Docker pulls failed with `x509: certificate has expired or is not yet
# valid` (the proxy intercepts TLS), so the first assembly happened on a runner.
#
# See docker-compose.yml for how the pieces fit together.

# PHP 8.4 is a hard floor here, not a preference: 14 production packages in the
# committed lock (the whole Symfony 8 line — clock, error-handler, http-kernel,
# mailer, mime, process, routing, string, translation, uid, var-dumper, …) declare
# `php >=8.4.1`, and none of the 94 declares a constraint that 8.4.26 fails. On the
# previous `php:8.3` base the `composer install` below refused the lock outright
# ("Your lock file does not contain a compatible set of packages", exit code 2) and
# the image never built. composer.json still says `^8.3` — that is what a bare
# `composer update` on 8.3 would resolve against; the lock is what pins the floor.
FROM php:8.4-fpm-alpine AS base

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

# The lock IS committed, so the image is reproducible: `install` resolves the exact
# tree CI tested. The old `composer update` fallback is gone on purpose — it built an
# image out of dependencies nobody had tested and hid the fact that the lock was
# missing. If the lock ever disappears, this fails loudly instead.
#
# The tree is copied before resolving rather than copying composer.json first. The
# usual trick (`COPY composer.json composer.lock ./`) keeps the dependency layer
# cacheable, but it also caches a layer whose inputs are only the two manifests,
# which is a poor trade while the dependency set is still moving. The layer can be
# split later; correctness first.
COPY . .

RUN composer install --no-dev --prefer-dist --no-scripts \
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
