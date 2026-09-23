#!/bin/bash
set -e

cd /var/www/html

# Install dependencies if needed (first run)
if [ ! -d vendor ]; then
    composer install --no-dev --no-interaction
fi

# Generate and persist APP_KEY on first run, unless one was supplied by the environment
if [ -z "${APP_KEY:-}" ] && [ ! -f .env ]; then
    echo "APP_KEY=" > .env
    php artisan key:generate --force
fi

# Run migrations
php artisan migrate --force -n

# Create default admin user if none exists
php artisan solder:setup --no-interaction

# The source bind mount masks the image's public/build, so restore the assets baked at build time.
if [ -d /opt/solder-assets/build ]; then
    rm -rf public/build
    cp -r /opt/solder-assets/build public/build
fi

# Ensure the web server can write to storage and cache
chgrp -R www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;

# Uploads are written by php-fpm as www-data; archives copied in over SSH arrive as root.
MODS_DIR="${SOLDER_REPO_LOCATION:-/var/www/}"
MODS_DIR="${MODS_DIR%/}/mods"
if [ -d "$MODS_DIR" ]; then
    chown -R www-data:www-data "$MODS_DIR"
fi

exec php-fpm
