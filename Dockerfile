FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json ./

RUN npm install --no-audit --no-fund

COPY . .

RUN npm run build

FROM composer:2.8 AS production-dependencies

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .

RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts

FROM composer:2.8 AS test-dependencies

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-interaction --no-scripts

FROM php:8.5-fpm-alpine AS php-base

RUN apk add --no-cache \
    freetype-dev \
    icu-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    linux-headers \
    oniguruma-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j1 bcmath gd intl pcntl pdo_mysql zip \
    # 6.x é obrigatório: a partir do PHP 8.4 a smart_str saiu do core e o
    # phpredis 5.x deixa de compilar (php_smart_string.h inexistente).
    # A 6.x também muda a assinatura de FCALL para arrays e passa a devolver
    # `false` + getLastError() em vez de lançar excepções.
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/html

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-encurta-link.ini
COPY docker/entrypoint.sh /usr/local/bin/encurta-link-entrypoint

ENTRYPOINT ["sh", "/usr/local/bin/encurta-link-entrypoint"]

CMD ["php-fpm", "-F"]

FROM php-base AS runtime

COPY --from=production-dependencies /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

FROM php-base AS test

COPY --from=test-dependencies /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
