# Development-only Horizon runner: the master process needs pcntl/posix, which Windows PHP lacks, so the supervisor runs
# in Linux while Herd keeps serving the web app.
# The project is bind-mounted at runtime - this image carries only the PHP runtime and extensions, never the code.
FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pcntl pdo_pgsql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

CMD ["php", "artisan", "horizon"]
