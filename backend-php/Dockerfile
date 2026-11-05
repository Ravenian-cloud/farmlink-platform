FROM composer:2 AS composer-bin

FROM php:8.3-apache-bookworm AS runtime

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
ENV APACHE_RUN_DIR=/tmp/apache2
ENV APACHE_LOCK_DIR=/tmp/apache2
ENV APACHE_LOG_DIR=/tmp/apache2
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libonig-dev unzip \
    && docker-php-ext-install pdo_pgsql opcache mbstring bcmath zip \
    && a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!Listen 80!Listen 8080!' /etc/apache2/ports.conf \
    && printf '\n<VirtualHost *:8080>\n    DocumentRoot /var/www/html/public\n    <Directory /var/www/html/public>\n        AllowOverride All\n        Require all granted\n    </Directory>\n</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf \
    && printf '\nHeader always set X-Content-Type-Options \"nosniff\"\nHeader always set Referrer-Policy \"strict-origin-when-cross-origin\"\nHeader always set X-Frame-Options \"DENY\"\nHeader always set Strict-Transport-Security \"max-age=31536000\"\n' > /etc/apache2/conf-available/farmlink-security-headers.conf \
    && a2enconf farmlink-security-headers \
    && printf '\nServerTokens Prod\nServerSignature Off\n' >> /etc/apache2/conf-available/security.conf \
    && a2enconf security \
    && mkdir -p /tmp/apache2 \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /tmp/apache2 storage bootstrap/cache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY --chown=www-data:www-data . .
COPY docker/entrypoint.sh /usr/local/bin/farmlink-entrypoint

RUN test -f composer.lock || (echo "A reviewed composer.lock is required for a reproducible production build." >&2 && exit 1) \
    && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    && rm -rf tests phpunit.xml \
    && chmod 755 /usr/local/bin/farmlink-entrypoint

USER www-data
ENTRYPOINT ["/usr/local/bin/farmlink-entrypoint"]
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r '$headers = @get_headers("http://127.0.0.1:8080/up"); exit($headers !== false && str_contains($headers[0], "200") ? 0 : 1);'

CMD ["apache2-foreground"]
