# This was updated with the latest *compatible* packages on 10/5/2023 by Sam. Let me know if something doesn't build properly.
FROM php:8.3-fpm-alpine

RUN apk add libpq-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo pdo_pgsql pgsql

RUN apk update; \
    apk upgrade; \
    apk add autoconf g++ libmcrypt libmcrypt-dev libzip libzip-dev linux-headers make oniguruma oniguruma-dev openldap-dev openssl openssl-dev unixodbc-dev;
    
RUN pecl install xdebug \
    && pecl install mcrypt \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install \
      ldap \
      zip \
    && docker-php-ext-enable \
      mcrypt \
      opcache \
      xdebug

# Latest release
COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

COPY uploads.ini /usr/local/etc/php/conf.d/
COPY www.conf /usr/local/etc/php-fpm.d/www.conf