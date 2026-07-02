# backend/Dockerfile
# ─── Base stage - shared system deps + PHP extensions ────────────────────────
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    postgresql-dev \
    postgresql-client \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    supervisor \
    nginx

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        intl \
        mbstring \
        xml \
        bcmath \
        opcache \
        pcntl

# Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# ─── Development stage ────────────────────────────────────────────────────────
FROM base AS development

# Xdebug for local debugging
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

COPY . .

# Watermark asset for ImageService - copied explicitly (not relying on the
# blanket COPY . . above) since storage/app/* is commonly excluded by
# .dockerignore/.gitignore as a runtime-only directory. Place watermark.png
# at the repo root (same level as this Dockerfile) before building.
COPY watermark.png /var/www/storage/app/watermark.png

# Override BROADCAST_CONNECTION so Laravel never tries to boot Reverb
# during package:discover - real env vars are injected at container start.
RUN BROADCAST_CONNECTION=log APP_ENV=local APP_KEY=placeholder \
    composer dump-autoload --optimize

RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache \
    && mkdir -p /var/log/supervisor /var/log/nginx /var/log/php-fpm \
    && chmod -R 755 /var/log/supervisor /var/log/nginx /var/log/php-fpm

COPY docker/php/conf.d/xdebug.ini       /usr/local/etc/php/conf.d/xdebug.ini
COPY docker/php/conf.d/custom.ini       /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/php-fpm.d/www.conf      /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx/laravel.conf          /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

# ─── Builder stage - installs prod deps, no app secrets needed ───────────────
FROM base AS builder

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --optimize-autoloader

COPY . .

# Watermark asset for ImageService - same reasoning as the development
# stage above: explicit COPY since storage/app/* is commonly .dockerignore'd.
COPY watermark.png /var/www/storage/app/watermark.png

# Same override for the production builder stage
RUN BROADCAST_CONNECTION=log APP_ENV=production APP_KEY=placeholder \
    composer dump-autoload --optimize --classmap-authoritative

# ─── Production stage ─────────────────────────────────────────────────────────
FROM base AS production

# Copy only the fully-built app from builder - no composer in prod image
COPY --from=builder --chown=www-data:www-data /var/www /var/www

# Copy entrypoint - fixes volume permissions and runs artisan bootstrap commands
# at container start time when real env vars are available
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

RUN mkdir -p /var/log/supervisor /var/log/nginx /var/log/php-fpm \
    && chmod -R 755 /var/log/supervisor /var/log/nginx /var/log/php-fpm

COPY docker/php/conf.d/production.ini   /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/php-fpm.d/www.conf      /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx/laravel.conf          /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -sf http://localhost/api/health || exit 1

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]