#!/usr/bin/env sh
set -eu
[ -f .env ] || cp .env.example .env
docker compose build
docker compose run --rm laravel php artisan key:generate
docker compose run --rm laravel php artisan migrate --force
docker compose run --rm laravel php artisan filament:assets
docker compose up -d
