#!/usr/bin/env sh
set -eu

mkdir -p storage/app storage/audio-clips storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
