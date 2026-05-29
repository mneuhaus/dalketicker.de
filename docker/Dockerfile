FROM dunglas/frankenphp:1-php8.4

# PHP extensions needed for Symfony + PostgreSQL
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    opcache \
    zip \
    gd \
    apcu

# Application PHP settings
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app

# FrankenPHP listens on :80 inside the container; Traefik terminates TLS.
EXPOSE 80
