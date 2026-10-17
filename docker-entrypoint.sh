#!/bin/sh
set -eu
mkdir -p /var/www/html/data/lang /var/www/html/data/tls
# Self-signed HTTPS is always available on container port 443; admin may upload
# a certificate and private key. Restart the container after any replacement.
if [ ! -s /var/www/html/data/tls/server.crt ] || [ ! -s /var/www/html/data/tls/server.key ]; then
  openssl req -x509 -nodes -newkey rsa:2048 -days 365 \
    -keyout /var/www/html/data/tls/server.key \
    -out /var/www/html/data/tls/server.crt \
    -subj '/CN=homelab-griller.local' >/dev/null 2>&1
fi
chmod 600 /var/www/html/data/tls/server.key 2>/dev/null || true
chmod 644 /var/www/html/data/tls/server.crt 2>/dev/null || true
chown -R www-data:www-data /var/www/html/data || true
exec docker-php-entrypoint "$@"
