FROM php:8.3-apache

LABEL org.opencontainers.image.title="HomeLab Griller by Pengu" \
      org.opencontainers.image.description="Modern BBQ event planner with admin area, public guest orders and grill-master todo board" \
      org.opencontainers.image.licenses="MIT"

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY app/ /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/homelab-griller-entrypoint
RUN chmod +x /usr/local/bin/homelab-griller-entrypoint \
    && mkdir -p /var/www/html/data/lang \
    && chown -R www-data:www-data /var/www/html/data \
    && printf '%s\n' '<Directory "/var/www/html/data">' 'Require all denied' '</Directory>' > /etc/apache2/conf-available/griller-data-deny.conf \
    && a2enconf griller-data-deny >/dev/null \
    && printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/griller-servername.conf \
    && a2enconf griller-servername >/dev/null

ENTRYPOINT ["homelab-griller-entrypoint"]
CMD ["apache2-foreground"]
EXPOSE 80
