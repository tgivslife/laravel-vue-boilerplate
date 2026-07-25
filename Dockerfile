# Production image on the laravel-alpine base (github.com/tgivslife/laravel-alpine):
# nginx + php-fpm + supervisor + cron in one container, TLS terminated upstream.
# The -build variant adds Composer/Node/npm/git for the build stage;
# the runtime stage ships neither toolchain nor node_modules.
#
# Runtime toggles inherited from the base image (set per deployment, not here):
#   LARAVEL_HORIZON_ENABLE=1    run Horizon under supervisor in this container
#   LARAVEL_SCHEDULER_ENABLE=0  disable the cron scheduler (default on)
#   NGINX_SET_REAL_IP_FROM      optional nginx-layer client IPs; interacts with
#                               TRUSTED_PROXIES - see docs/deployment.md
# App-specific:
#   LARAVEL_MIGRATE_ENABLE=0    skip the startup migration (default on)

ARG REGISTRY=docker.io/stsdockerhub
ARG BASE_TAG=8.5.8-laravel-alpine3.24

FROM ${REGISTRY}/php:${BASE_TAG}-build AS build

WORKDIR /var/www/html

# composer's post-autoload package:discover boots the app, and the environment security checks fail-loud in any
# deployed env - including 'production', the default when APP_ENV is unset.
# local is exempt; the real APP_ENV arrives at runtime from the orchestrator.
ENV APP_ENV=local

COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --no-progress \
    && npm ci --no-audit --no-fund \
    && npm run build \
    && rm -rf node_modules docker

###################################################################################################

FROM ${REGISTRY}/php:${BASE_TAG}

WORKDIR /var/www/html

# storage/ and bootstrap/cache must be writable by php-fpm (www-data):
# the entrypoint writes the config/route/view caches there at startup.
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html

# Startup steps appended after the base image's own 1-6 (php, opcache, fpm, nginx, scheduler, horizon):
# cache the framework, verify the security configuration, migrate. Any failure aborts startup (set -e in each script).
COPY --chmod=755 docker/docker-entrypoint.d /docker-entrypoint.d/

# No .env is baked in and no APP_KEY is generated at build time: configuration, including APP_KEY, is injected by the orchestrator.
# `security:diagnose` and the boot-time environment checks refuse to serve if it arrives incomplete.
