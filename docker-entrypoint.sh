#!/bin/sh
set -eu

mkdir -p /var/www/html/uploads/profiles
cp /usr/local/share/uploads.htaccess /var/www/html/uploads/.htaccess
chown -R www-data:www-data /var/www/html/uploads

exec "$@"
