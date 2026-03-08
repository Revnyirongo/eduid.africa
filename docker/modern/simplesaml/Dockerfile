FROM composer:2 AS build
WORKDIR /srv
RUN composer create-project simplesamlphp/simplesamlphp:^2.0 simplesamlphp

FROM php:8.2-fpm-bullseye
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       libicu-dev \
       libzip-dev \
       libxml2-dev \
       unzip \
    && docker-php-ext-install -j"$(nproc)" intl zip opcache \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/simplesamlphp
COPY --from=build /srv/simplesamlphp /var/www/simplesamlphp
COPY docker/modern/simplesaml/php.ini /usr/local/etc/php/conf.d/zz-simplesaml.ini

ENV SIMPLESAMLPHP_CONFIG_DIR=/etc/simplesamlphp

RUN chown -R www-data:www-data /var/www/simplesamlphp
USER www-data

EXPOSE 9000
CMD ["php-fpm"]
