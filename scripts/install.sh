#!/usr/bin/env sh
set -eu
[ -f .env ] || cp .env.example .env
docker compose build
docker compose run --rm laravel php artisan key:generate
docker compose run --rm laravel php artisan migrate --force
docker compose up -d
