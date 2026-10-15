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
#
# Two hadolint findings are accepted here on purpose, and named rather than left
# implicit so that a genuinely new one stays visible:
#
#   SC2086 — `$PHPIZE_DEPS` is a space-separated list that MUST be word-split;
#            quoting it would make `apk add` look for a single package called
#            "autoconf dpkg-dev dpkg …". The list is not spelled out literally
#            because it belongs to the base image and drifts with it — copying it
#            here would freeze today's contents and silently miss tomorrow's.
#   DL3018 — pinning apk versions in a base image is a losing trade: Alpine drops
#            a pinned build from its repositories on the next point release, and
#            the image then stops building until someone bumps the pins by hand.
#            `--no-cache` already avoids the stale-index failure this rule exists
#            to prevent.
#
# hadolint ignore=SC2086,DL3018
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
# Dependencies are resolved here, inside the same PHP 8.4 base, so the platform
# Composer checks against is the platform that will actually run the code.
# Resolving in a `composer:2` container instead risks pinning versions against a
# different PHP.
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
#
# The three framework directories are written out in full instead of as
# `storage/framework/{cache,sessions,views}`: brace expansion is undefined in POSIX
# sh (SC3009), and this RUN executes under Alpine's BusyBox `sh`. It happens to
# work there today, but it is not a guarantee the image can rely on — a base image
# rebuilt without `ASH_BASH_COMPAT` would create a literal directory named
# `{cache,sessions,views}` and the application would fail at runtime instead of at
# build time.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
        storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# The user is named, not numeric (DL3066), and that is deliberate: the same name
# is what `chown -R www-data:www-data` above resolves, so the two cannot drift.
# A hardcoded `USER 82:82` would keep working only as long as the base image keeps
# assigning uid 82 to www-data — and the failure mode if it ever changed would be
# "permission denied" on storage at runtime, not a build error.
# hadolint ignore=DL3066
USER www-data

EXPOSE 9000

CMD ["php-fpm"]
