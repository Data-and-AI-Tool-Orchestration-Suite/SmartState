FROM php:8.5-fpm-alpine

RUN apk add libpq-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo pdo_pgsql pgsql

RUN apk update; \
    apk upgrade; \
    apk add autoconf g++ libzip libzip-dev linux-headers make oniguruma oniguruma-dev openldap-dev openssl openssl-dev unixodbc-dev;

RUN docker-php-ext-configure ldap \
    && docker-php-ext-install \
      ldap \
      zip

# Latest release
COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

COPY uploads.ini /usr/local/etc/php/conf.d/
COPY www.conf /usr/local/etc/php-fpm.d/www.conf

# Installs the site's composer dependencies on start (the site is bind-mounted,
# so this cannot happen at build time), then hands off to php-fpm.
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
