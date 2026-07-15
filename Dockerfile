FROM composer:2 AS composer

WORKDIR /app
COPY . .
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PORT=5000

RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf \
    && sed -ri "s/^Listen [0-9]+$/Listen ${PORT}/" /etc/apache2/ports.conf \
    && sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" \
        /etc/apache2/sites-available/*.conf

WORKDIR /var/www/html
COPY --from=composer /app /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/shop-entrypoint

RUN chmod +x /usr/local/bin/shop-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache database

# Render scans this port; the entrypoint still honors a different runtime PORT.
EXPOSE 5000

ENTRYPOINT ["shop-entrypoint"]
CMD ["apache2-foreground"]
