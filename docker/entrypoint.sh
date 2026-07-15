#!/bin/sh
set -e

cd /var/www/html

# Platforms such as Render assign the public port at runtime.
http_port="${PORT:-80}"
sed -ri "s/^Listen [0-9]+$/Listen ${http_port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${http_port}>/" /etc/apache2/sites-available/*.conf
echo "ServerName localhost" > /etc/apache2/conf-available/server-name.conf
a2enconf server-name >/dev/null

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    database

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    database_path="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    mkdir -p "$(dirname "$database_path")"
    touch "$database_path"
fi

chown -R www-data:www-data storage bootstrap/cache database

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache

exec "$@"
