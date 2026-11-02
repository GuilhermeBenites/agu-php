FROM php:8.1.34-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libicu-dev libonig-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl mbstring pdo_mysql zip opcache \
    && pecl install pcov-1.0.12 redis-6.1.0 \
    && docker-php-ext-enable pcov redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY docker/php.ini /usr/local/etc/php/conf.d/agu.ini
COPY . .
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts
CMD ["php-fpm"]
