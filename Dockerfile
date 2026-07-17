# Etapa 1: Dependencias PHP (Composer)
FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --no-scripts \
    --ignore-platform-reqs

# Etapa 2: Assets frontend (Vite + Tailwind)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources/ resources/
RUN npm run build

# Etapa 3: Imagen final (Apache + mod_php, un solo proceso para Render)
FROM php:8.4-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        bcmath \
        fileinfo \
        mbstring \
        exif \
    && pecl install redis \
    && docker-php-ext-enable redis

RUN a2enmod rewrite \
    && rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* \
    && a2enmod mpm_prefork; \
    echo "=== MPM modules enabled ==="; \
    ls -la /etc/apache2/mods-enabled/ | grep mpm

RUN rm /etc/apache2/sites-enabled/000-default.conf
COPY docker/apache-laravel.conf /etc/apache2/sites-available/000-default.conf
RUN ln -s /etc/apache2/sites-available/000-default.conf /etc/apache2/sites-enabled/000-default.conf

COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY --from=composer /app/vendor /var/www/html/vendor
COPY --from=assets /app/public/build /var/www/html/public/build

COPY . /var/www/html

RUN rm -f bootstrap/cache/*.php \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
