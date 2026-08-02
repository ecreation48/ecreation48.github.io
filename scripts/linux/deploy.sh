#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/voice-guardian}"
APP_USER="${APP_USER:-voiceguardian}"

if [[ "$EUID" -ne 0 ]]; then
  echo "Lance ce script en root : sudo bash scripts/linux/deploy.sh"
  exit 1
fi

cd "$APP_DIR"

if [[ -d .git ]]; then
  git config --global --add safe.directory "$APP_DIR" || true
  git pull --ff-only
fi

install -d -o "$APP_USER" -g www-data apps/web/storage apps/web/bootstrap/cache apps/discord-manager/storage/audio-clips
chown -R "$APP_USER":www-data apps/web/storage apps/web/bootstrap/cache apps/discord-manager/storage
chmod -R ug+rwX apps/web/storage apps/web/bootstrap/cache apps/discord-manager/storage

sudo -u "$APP_USER" composer install --working-dir=apps/web --no-dev --prefer-dist --no-interaction --optimize-autoloader
sudo -u "$APP_USER" npm ci
sudo -u "$APP_USER" npm -w apps/discord-manager run build

if ! grep -q '^APP_KEY=base64:' /etc/voice-guardian/web.env; then
  sudo -u "$APP_USER" php apps/web/artisan key:generate --force
fi

sudo -u "$APP_USER" php apps/web/artisan migrate --force
sudo -u "$APP_USER" php apps/web/artisan filament:assets
sudo -u "$APP_USER" php apps/web/artisan optimize:clear
sudo -u "$APP_USER" php apps/web/artisan config:cache
sudo -u "$APP_USER" php apps/web/artisan route:cache
sudo -u "$APP_USER" php apps/web/artisan view:cache

systemctl restart voice-guardian-queue.service voice-guardian-discord.service || true
systemctl reload nginx || systemctl restart nginx || true

echo "Déploiement terminé."
