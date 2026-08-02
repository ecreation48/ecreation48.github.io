FROM php:8.4-fpm-alpine
RUN apk add --no-cache git unzip icu-dev libpq-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl pdo_pgsql zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY apps/web/composer.* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts
COPY apps/web .
COPY docker/php/entrypoint.sh /usr/local/bin/voice-guardian-entrypoint
RUN chmod +x /usr/local/bin/voice-guardian-entrypoint \
    && composer dump-autoload --optimize \
    && mkdir -p storage/app storage/audio-clips storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public/css public/js
ENTRYPOINT ["voice-guardian-entrypoint"]
CMD ["php-fpm", "-F"]
