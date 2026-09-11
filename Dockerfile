# ---------- Stage 1: Build frontend assets (Vite + React) ----------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json* .npmrc* ./
# npm 10.9.8 on Coolify rejects the existing cross-platform optional WASM lock metadata
# as out-of-sync (@emnapi/core/runtime). `npm install` reconciles package-lock.json
# inside the build container before installing, while legacy-peer-deps avoids optional
# peer-resolution conflicts. This is intentionally explicit instead of relying only on .npmrc.
RUN npm install --no-audit --no-fund --ignore-scripts --legacy-peer-deps

COPY vite.config.js tsconfig.json ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# ---------- Stage 2: Install PHP dependencies (Composer) ----------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# ---------- Stage 3: Production image (PHP 8.4 + Apache) ----------
FROM php:8.4-apache

# System deps + PHP extensions required by Laravel
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libicu-dev ffmpeg \
        unzip curl default-mysql-client tzdata \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath zip exif gd intl opcache \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache: serve Laravel from /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# PHP production settings (upload limits sized for chunked media uploads)
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /var/www/html

# Application source
COPY . .

# Dependencies and built assets from previous stages
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Writable dirs
RUN mkdir -p storage/app/media/uploads/images \
             storage/app/media/uploads/videos \
             storage/app/media/uploads/audios \
             storage/app/media/uploads/documents \
             storage/app/media/thumbnails \
             storage/app/private/chunks \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

# Container-native healthcheck. Coolify Docker Compose deployments can read the
# Compose healthcheck, while Dockerfile/Application deployments detect this
# HEALTHCHECK directly. Using 127.0.0.1 avoids hostname/IPv6 ambiguity.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=5 \
  CMD curl -fsS --max-time 4 http://127.0.0.1/up >/dev/null || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
