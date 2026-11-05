# Supported PHP versions: 8.2, 8.3, 8.5
ARG PHP_VERSION=8.5
# PHP 8.5 support requires compatible extension builds (check install-php-extensions)

###########################################
# Composer dependencies stage
###########################################
FROM php:${PHP_VERSION}-cli-alpine AS composer-deps

WORKDIR /app

# Install required extensions for composer install.
#
# bcmath is here because moneyphp/money (via laravel/cashier) hard-requires it,
# and `composer install` validates platform requirements even with --no-scripts
# --no-autoloader. Without it this stage fails outright:
#
#   moneyphp/money v4.9.0 requires ext-bcmath * -> it is missing from your system
#
# The final stage installs bcmath already, so only this build stage was short.
# Keep this list a superset of the ext-* the locked tree requires, minus the ones
# ignored below.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl sockets zip bcmath

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy composer files, and the path repository composer.json points at
COPY composer.json composer.lock ./
COPY modules/users-roles ./modules/users-roles

# Install composer dependencies (no autoloader yet, will optimize in final stage)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-ansi \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-req=ext-pcntl

###########################################
# Frontend asset stage
###########################################
# The image used to ship public/build/manifest.json and no assets at all: nothing
# ran npm, and only the manifest was committed (public/build is gitignored). Every
# @vite tag in layouts/app.blade.php and components/guest-layout.blade.php resolved
# against a manifest naming 32 files that were not in the image, so all 32 404'd.
# The app booted and served with no CSS and no JS.
#
# Node 20: package.json declares no engines, but vite 8 needs >= 20.19, and 20 is
# what the php image ships (v20.20.2), so the two agree.
#
# Ordered after composer-deps because it needs that stage's vendor tree (below).
FROM node:20-alpine AS assets

WORKDIR /app

# Lock first, so a source-only change reuses the npm ci layer.
COPY package.json package-lock.json ./
RUN npm ci

# The whole (dockerignored) tree, not just resources/: the CSS is Tailwind v4 via
# `@import 'tailwindcss'` with no @source directive, so Tailwind auto-detects what
# to scan. It needs the blade views AND app/ — Filament's PHP carries class
# strings — or it emits a stylesheet with the utilities stripped out, which is
# worse than a 404 because it looks like it worked.
COPY . .

# resources/css/filament/admin/theme.css does
# `@import '../../../../vendor/filament/filament/resources/css/theme.css'`, so
# building the CSS needs the PHP vendor tree. Taken from composer-deps rather than
# the build context on purpose: .dockerignore excludes vendor/ precisely so a
# developer's dev-dependency-laden copy cannot ride in, and this stage gets the
# same --no-dev tree the application itself ships.
COPY --from=composer-deps /app/vendor ./vendor

# Builds all three vite inputs: resources/css/app.css, resources/js/app.js and
# resources/css/filament/admin/theme.css.
RUN npm run build

###########################################
# Main application stage
###########################################
FROM php:${PHP_VERSION}-cli-alpine

LABEL maintainer="SMortexa <seyed.me720@gmail.com>"
LABEL org.opencontainers.image.title="Laravel Octane Dockerfile"
LABEL org.opencontainers.image.description="Production-ready Dockerfile for Laravel Octane"
LABEL org.opencontainers.image.source=https://github.com/exaco/laravel-octane-dockerfile
LABEL org.opencontainers.image.licenses=MIT

ARG WWWUSER=1000
ARG WWWGROUP=1000
ARG TZ=UTC

ENV TERM=xterm-color \
    WITH_HORIZON=false \
    WITH_SCHEDULER=false \
    WITH_REVERB=false \
    OCTANE_SERVER=roadrunner \
    USER=octane \
    ROOT=/var/www/html \
    COMPOSER_FUND=0 \
    COMPOSER_MAX_PARALLEL_HTTP=24

WORKDIR ${ROOT}

SHELL ["/bin/sh", "-lc"]

RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
  && echo ${TZ} > /etc/timezone

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install system dependencies and PHP extensions in one layer
RUN apk update && \
    apk upgrade && \
    apk add --no-cache \
    curl \
    wget \
    nano \
    ncdu \
    procps \
    ca-certificates \
    supervisor \
    libsodium-dev && \
    install-php-extensions \
    bz2 \
    pcntl \
    mbstring \
    bcmath \
    sockets \
    pgsql \
    pdo_pgsql \
    opcache \
    exif \
    pdo_mysql \
    zip \
    intl \
    gd \
    redis \
    pcntl \
    igbinary && \
    docker-php-source delete && \
    rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

RUN arch="$(apk --print-arch)" \
    && case "$arch" in \
    armhf) _cronic_fname='supercronic-linux-arm' ;; \
    aarch64) _cronic_fname='supercronic-linux-arm64' ;; \
    x86_64) _cronic_fname='supercronic-linux-amd64' ;; \
    x86) _cronic_fname='supercronic-linux-386' ;; \
    *) echo >&2 "error: unsupported architecture: $arch"; exit 1 ;; \
    esac \
    && wget -q "https://github.com/aptible/supercronic/releases/download/v0.2.29/${_cronic_fname}" \
    -O /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo "*/1 * * * * php ${ROOT}/artisan schedule:run --no-interaction" > /etc/supercronic/laravel

RUN addgroup -g ${WWWGROUP} ${USER} \
    && adduser -D -h ${ROOT} -G ${USER} -u ${WWWUSER} -s /bin/sh ${USER}

RUN mkdir -p /var/log/supervisor /var/run/supervisor \
    && chown -R ${USER}:${USER} ${ROOT} /var/log /var/run \
    && chmod -R a+rw ${ROOT} /var/log /var/run

RUN cp ${PHP_INI_DIR}/php.ini-production ${PHP_INI_DIR}/php.ini

USER ${USER}

# Install Composer from official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy vendor from composer-deps stage for better caching
COPY --chown=${USER}:${USER} --from=composer-deps /app/vendor ./vendor

# Copy composer files (needed for autoloader generation)
COPY --chown=${USER}:${USER} composer.json composer.lock ./

# Copy application code first so autoloader can resolve all files
COPY --chown=${USER}:${USER} . .

# After `COPY . .`, deliberately: the built assets must land on top of the source
# tree, not be overwritten by it. public/build is excluded in .dockerignore so
# the only copy that can win here is the one the assets stage just built.
COPY --chown=${USER}:${USER} --from=assets /app/public/build ./public/build

# Generate optimized autoloader now that all app files are present
RUN composer dump-autoload --classmap-authoritative --no-dev && \
    composer clear-cache

# Create necessary Laravel directories
RUN mkdir -p \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache && \
    chmod -R a+rw storage

# Copy configuration files
COPY --chown=${USER}:${USER} .docker/supervisord.conf /etc/supervisor/
COPY --chown=${USER}:${USER} .docker/octane/RoadRunner/supervisord.roadrunner.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/supervisord.horizon.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/supervisord.reverb.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/supervisord.scheduler.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/supervisord.worker.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/php.ini ${PHP_INI_DIR}/conf.d/99-octane.ini
COPY --chown=${USER}:${USER} .docker/start-container /usr/local/bin/start-container

# Copy environment file
COPY --chown=${USER}:${USER} .env.example ./.env

RUN chmod +x /usr/local/bin/start-container && \
    cat .docker/utilities.sh >> ~/.bashrc

EXPOSE 8000
EXPOSE 8080

ENTRYPOINT ["start-container"]

HEALTHCHECK --start-period=5s --interval=2s --timeout=5s --retries=8 CMD php artisan octane:status || exit 1

