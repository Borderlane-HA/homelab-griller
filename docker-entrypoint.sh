#!/bin/sh
set -e
mkdir -p /var/www/html/data/lang
chown -R www-data:www-data /var/www/html/data || true
exec docker-php-entrypoint "$@"
