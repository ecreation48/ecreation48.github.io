#!/usr/bin/env sh
set -eu

if [ ! -f .env ]; then
  cp .env.docker.example .env
fi

docker compose build
docker compose up -d postgres redis
docker compose run --rm laravel php artisan key:generate --force
docker compose run --rm laravel php artisan migrate --force
docker compose run --rm laravel php artisan filament:assets
docker compose run --rm laravel php artisan optimize:clear
docker compose run --rm laravel php artisan config:cache
docker compose run --rm laravel php artisan route:cache
docker compose run --rm laravel php artisan view:cache
docker compose up -d
