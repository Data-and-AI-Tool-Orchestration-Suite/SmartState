#!/bin/sh
set -e

# The site is bind-mounted at runtime, so dependencies must be installed on
# start rather than at image build time.
if [ -f /var/www/html/composer.json ]; then
    COMPOSER_ALLOW_SUPERUSER=1 composer install \
        -d /var/www/html --no-interaction --no-progress
fi

exec docker-php-entrypoint "$@"
