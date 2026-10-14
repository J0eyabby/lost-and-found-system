FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev \
    && docker-php-ext-install mysqli zip \
    && a2enmod rewrite \
    && printf "upload_max_filesize=10M\npost_max_size=12M\nmax_file_uploads=5\n" > /usr/local/etc/php/conf.d/uploads.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . .

COPY uploads/.htaccess /usr/local/share/uploads.htaccess
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
