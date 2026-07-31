FROM php:8.3-fpm-alpine
RUN apk add --no-cache git unzip libpq-dev $PHPIZE_DEPS && docker-php-ext-install pdo_pgsql
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY apps/web/composer.* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts
COPY apps/web .
RUN composer dump-autoload --optimize && mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache
CMD ["php-fpm", "-F"]
