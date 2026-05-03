FROM composer:2 AS vendor
WORKDIR /srv/app
COPY app-modern/composer.json app-modern/composer.lock ./
COPY simplesamlphp/modules/ubuntunetbroker /srv/simplesamlphp/modules/ubuntunetbroker
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
COPY app-modern/ .
RUN composer dump-autoload --optimize

FROM php:8.2-fpm-bullseye
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       libicu-dev \
       libzip-dev \
       libpq-dev \
       libxml2-dev \
       unzip \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip opcache xml dom simplexml \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app
COPY --from=vendor /srv/app /var/www/app
COPY conf/simplesamlphp /var/www/conf/simplesamlphp
COPY certs /var/www/certs
COPY docker/modern/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
RUN [ -f /var/www/app/.env ] || printf "APP_ENV=prod\nAPP_DEBUG=0\n" > /var/www/app/.env

RUN chown -R www-data:www-data /var/www/app /var/www/conf /var/www/certs

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
