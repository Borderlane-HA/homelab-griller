#!/bin/sh
set -eu
mkdir -p /var/www/html/data/lang
if [ ! -f /var/www/html/data/admin-configured ] && [ ! -f /var/www/html/data/setup-token ]; then
  # A setup token is only needed until the admin creates a password in the UI.
  if [ -n "${GRILLER_SETUP_TOKEN:-}" ]; then
    printf '%s' "$GRILLER_SETUP_TOKEN" > /var/www/html/data/setup-token
  else
    od -An -N12 -tx1 /dev/urandom | tr -d ' \n' > /var/www/html/data/setup-token
  fi
fi
[ ! -f /var/www/html/data/setup-token ] || chmod 600 /var/www/html/data/setup-token 2>/dev/null || true
chown -R www-data:www-data /var/www/html/data || true
if [ -f /var/www/html/data/setup-token ] && [ ! -f /var/www/html/data/admin-configured ]; then
  printf '\nHomeLab Griller v2 — first-run setup token:\n'
  cat /var/www/html/data/setup-token
  printf '\nVisit the web UI to set your own admin password. Keep the token private.\n'
fi
exec docker-php-entrypoint "$@"
