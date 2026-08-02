#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/voice-guardian}"
APP_USER="${APP_USER:-voiceguardian}"
REPO_URL="${REPO_URL:-https://github.com/ecreation48/ecreation48.github.io.git}"
BRANCH="${BRANCH:-main}"
PHP_VERSION="${PHP_VERSION:-}"
DB_NAME="${DB_NAME:-voice_guardian}"
DB_USER="${DB_USER:-voice_guardian}"
DB_PASSWORD="${DB_PASSWORD:-}"
WORKER_SERVICE_TOKEN="${WORKER_SERVICE_TOKEN:-}"

if [[ "$EUID" -ne 0 ]]; then
  echo "Lance ce script en root : sudo bash scripts/linux/install-server.sh"
  exit 1
fi

if [[ -z "$DB_PASSWORD" ]]; then
  DB_PASSWORD="$(openssl rand -base64 32 | tr -d '\n')"
fi

if [[ -z "$WORKER_SERVICE_TOKEN" ]]; then
  WORKER_SERVICE_TOKEN="$(openssl rand -base64 48 | tr -d '\n')"
fi

export DEBIAN_FRONTEND=noninteractive

apt-get update
if [[ -n "$PHP_VERSION" ]] && apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
  PHP_PACKAGES=(
    "php${PHP_VERSION}-fpm"
    "php${PHP_VERSION}-cli"
    "php${PHP_VERSION}-pgsql"
    "php${PHP_VERSION}-xml"
    "php${PHP_VERSION}-mbstring"
    "php${PHP_VERSION}-curl"
    "php${PHP_VERSION}-zip"
    "php${PHP_VERSION}-bcmath"
    "php${PHP_VERSION}-intl"
    "php${PHP_VERSION}-redis"
  )
elif apt-cache show php-fpm >/dev/null 2>&1; then
  PHP_PACKAGES=(
    php-fpm
    php-cli
    php-pgsql
    php-xml
    php-mbstring
    php-curl
    php-zip
    php-bcmath
    php-intl
    php-redis
  )
else
  echo "Aucun paquet PHP-FPM compatible n’a été trouvé dans les dépôts APT."
  echo "Installe PHP >= 8.3 puis relance ce script."
  exit 1
fi

apt-get install -y \
  ca-certificates curl git unzip build-essential cmake pkg-config ffmpeg nginx redis-server postgresql postgresql-contrib \
  "${PHP_PACKAGES[@]}"

PHP_FPM_SERVICE="$(systemctl list-unit-files 'php*-fpm.service' --no-legend | awk '{print $1}' | sort -V | tail -n 1)"
if [[ -z "$PHP_FPM_SERVICE" ]] && systemctl list-unit-files php-fpm.service --no-legend >/dev/null 2>&1; then
  PHP_FPM_SERVICE="php-fpm.service"
fi

if [[ -z "$PHP_FPM_SERVICE" ]]; then
  echo "Service PHP-FPM introuvable après installation."
  exit 1
fi

PHP_FPM_SOCK="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' 2>/dev/null | sort -V | tail -n 1)"
if [[ -z "$PHP_FPM_SOCK" ]]; then
  systemctl start "$PHP_FPM_SERVICE"
  PHP_FPM_SOCK="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' 2>/dev/null | sort -V | tail -n 1)"
fi

if [[ -z "$PHP_FPM_SOCK" ]]; then
  echo "Socket PHP-FPM introuvable dans /run/php."
  exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_SIGNATURE="$(curl -fsSL https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  if [[ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]]; then
    echo "Signature Composer invalide."
    rm -f /tmp/composer-setup.php
    exit 1
  fi
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

if ! command -v node >/dev/null 2>&1 || [[ "$(node -v | sed 's/^v//' | cut -d. -f1)" -lt 22 ]]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
fi

if ! id "$APP_USER" >/dev/null 2>&1; then
  useradd --system --create-home --shell /bin/bash --groups www-data "$APP_USER"
fi

install -d -o "$APP_USER" -g www-data /etc/voice-guardian "$APP_DIR"

if [[ ! -d "$APP_DIR/.git" ]]; then
  git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
else
  git -C "$APP_DIR" fetch origin "$BRANCH"
  git -C "$APP_DIR" checkout "$BRANCH"
  git -C "$APP_DIR" pull --ff-only origin "$BRANCH"
fi

chown -R "$APP_USER":www-data "$APP_DIR"

sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname = '$DB_USER'" | grep -q 1 || sudo -u postgres psql -c "CREATE USER $DB_USER WITH PASSWORD '$DB_PASSWORD';"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'" | grep -q 1 || sudo -u postgres createdb -O "$DB_USER" "$DB_NAME"

if [[ ! -f /etc/voice-guardian/web.env ]]; then
  cp "$APP_DIR/deploy/linux/web.env.example" /etc/voice-guardian/web.env
  sed -i "s#APP_URL=https://voice.example.com#APP_URL=http://$(hostname -I | awk '{print $1}')#g" /etc/voice-guardian/web.env
  sed -i "s#DB_DATABASE=voice_guardian#DB_DATABASE=$DB_NAME#g" /etc/voice-guardian/web.env
  sed -i "s#DB_USERNAME=voice_guardian#DB_USERNAME=$DB_USER#g" /etc/voice-guardian/web.env
  sed -i "s#DB_PASSWORD=change-me#DB_PASSWORD=$DB_PASSWORD#g" /etc/voice-guardian/web.env
  sed -i "s#WORKER_SERVICE_TOKEN=replace-with-a-long-random-token-at-least-24-chars#WORKER_SERVICE_TOKEN=$WORKER_SERVICE_TOKEN#g" /etc/voice-guardian/web.env
fi

if [[ ! -f /etc/voice-guardian/worker.env ]]; then
  cp "$APP_DIR/deploy/linux/worker.env.example" /etc/voice-guardian/worker.env
  sed -i "s#WORKER_SERVICE_TOKEN=replace-with-the-same-token-as-web-env#WORKER_SERVICE_TOKEN=$WORKER_SERVICE_TOKEN#g" /etc/voice-guardian/worker.env
fi

chown -R "$APP_USER":www-data /etc/voice-guardian
chmod 750 /etc/voice-guardian
chmod 640 /etc/voice-guardian/*.env

ln -sfn /etc/voice-guardian/web.env "$APP_DIR/apps/web/.env"

sed "s#@@PHP_FPM_SOCK@@#$PHP_FPM_SOCK#g" "$APP_DIR/deploy/linux/nginx/voice-guardian.conf" > /etc/nginx/sites-available/voice-guardian.conf
ln -sfn /etc/nginx/sites-available/voice-guardian.conf /etc/nginx/sites-enabled/voice-guardian.conf
rm -f /etc/nginx/sites-enabled/default

for service_file in "$APP_DIR"/deploy/linux/systemd/*.service; do
  sed "s#@@PHP_FPM_SERVICE@@#$PHP_FPM_SERVICE#g" "$service_file" > "/etc/systemd/system/$(basename "$service_file")"
done
cp "$APP_DIR"/deploy/linux/systemd/*.timer /etc/systemd/system/
systemctl daemon-reload

bash "$APP_DIR/scripts/linux/deploy.sh"

systemctl enable --now nginx "$PHP_FPM_SERVICE" redis-server postgresql
systemctl enable --now voice-guardian-queue.service voice-guardian-scheduler.timer voice-guardian-discord.service

echo
echo "Installation terminée."
echo "Admin : $(grep '^APP_URL=' /etc/voice-guardian/web.env | cut -d= -f2-)/admin"
echo "Crée le premier admin : sudo -u $APP_USER php $APP_DIR/apps/web/artisan make:filament-user"
echo "Logs worker Discord : journalctl -u voice-guardian-discord -f"
