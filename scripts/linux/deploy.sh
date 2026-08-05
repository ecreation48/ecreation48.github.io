#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/voice-guardian}"
APP_USER="${APP_USER:-voiceguardian}"

if [[ "$EUID" -ne 0 ]]; then
  echo "Lance ce script en root : sudo bash scripts/linux/deploy.sh"
  exit 1
fi

cd "$APP_DIR"

ensure_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"

  if [[ -f "$file" ]] && ! grep -q "^${key}=" "$file"; then
    echo "${key}=${value}" >> "$file"
  fi
}

env_value() {
  local file="$1"
  local key="$2"

  if [[ ! -f "$file" ]]; then
    return 0
  fi

  grep -E "^${key}=" "$file" | tail -n 1 | cut -d= -f2- | sed "s/^['\"]//; s/['\"]$//"
}

ensure_whisper_installation() {
  local worker_env="/etc/voice-guardian/worker.env"
  local provider
  local binary
  local model
  local model_name
  local whisper_dir

  provider="$(env_value "$worker_env" TRANSCRIPTION_PROVIDER)"
  if [[ "$provider" != "command" ]]; then
    return 0
  fi

  binary="$(env_value "$worker_env" WHISPER_CPP_BINARY)"
  model="$(env_value "$worker_env" WHISPER_CPP_MODEL)"

  if [[ -z "$binary" || -z "$model" ]]; then
    echo "Whisper local est activé, mais WHISPER_CPP_BINARY ou WHISPER_CPP_MODEL manque dans $worker_env."
    return 0
  fi

  if [[ -x "$binary" && -f "$model" ]]; then
    return 0
  fi

  model_name="$(basename "$model")"
  model_name="${model_name#ggml-}"
  model_name="${model_name%.bin}"
  whisper_dir="$(dirname "$(dirname "$model")")"

  echo "Installation Whisper requise : binaire ou modèle introuvable."
  echo "Binaire attendu : $binary"
  echo "Modèle attendu : $model"
  WHISPER_DIR="$whisper_dir" MODEL="$model_name" bash "$APP_DIR/scripts/linux/install-whisper.sh"
}

if [[ -d .git ]]; then
  git config --global --add safe.directory "$APP_DIR" || true
  git pull --ff-only
fi

install -d -o "$APP_USER" -g www-data apps/web/storage apps/web/bootstrap/cache apps/discord-manager/storage/audio-clips
chown -R "$APP_USER":www-data apps/web/storage apps/web/bootstrap/cache apps/discord-manager/storage
chmod -R ug+rwX apps/web/storage apps/web/bootstrap/cache apps/discord-manager/storage

ensure_env_value /etc/voice-guardian/worker.env DISCORD_CHANNEL_SYNC_INTERVAL_MS 120000
ensure_env_value /etc/voice-guardian/worker.env VOICE_MIN_HUMAN_MEMBERS 2
ensure_env_value /etc/voice-guardian/worker.env VOICE_INSUFFICIENT_MEMBERS_GRACE_MS 2000
ensure_env_value /etc/voice-guardian/worker.env VOICE_CHANNEL_LOCK_TTL_MS 45000
ensure_env_value /etc/voice-guardian/worker.env AUTO_BLOCKED_WORD_DETECTION true
ensure_env_value /etc/voice-guardian/worker.env AUTO_BLOCKED_WORD_INTERVAL_MS 30000
ensure_env_value /etc/voice-guardian/worker.env AUTO_BLOCKED_WORD_MAX_TRANSCRIPTIONS_PER_CYCLE 2
ensure_env_value /etc/voice-guardian/worker.env AUTO_BLOCKED_WORD_GLOBAL_TRANSCRIPTION_LIMIT 1
ensure_env_value /etc/voice-guardian/worker.env AUTO_BLOCKED_WORD_COOLDOWN_SECONDS 300

ensure_whisper_installation

sudo -u "$APP_USER" composer install --working-dir=apps/web --no-dev --prefer-dist --no-interaction --optimize-autoloader
sudo -u "$APP_USER" npm ci
sudo -u "$APP_USER" node scripts/patch-discord-voice-heartbeat.mjs
sudo -u "$APP_USER" npm -w apps/discord-manager run build

PHP_FPM_SERVICE="$(systemctl list-unit-files 'php*-fpm.service' --no-legend | awk '{print $1}' | sort -V | tail -n 1)"
if [[ -z "$PHP_FPM_SERVICE" ]] && systemctl list-unit-files php-fpm.service --no-legend >/dev/null 2>&1; then
  PHP_FPM_SERVICE="php-fpm.service"
fi

if [[ -n "$PHP_FPM_SERVICE" ]]; then
  for service_file in "$APP_DIR"/deploy/linux/systemd/*.service; do
    sed "s#@@PHP_FPM_SERVICE@@#$PHP_FPM_SERVICE#g" "$service_file" > "/etc/systemd/system/$(basename "$service_file")"
  done

  cp "$APP_DIR"/deploy/linux/systemd/*.timer /etc/systemd/system/
  systemctl daemon-reload
fi

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

HAS_ADMIN="yes"
sudo -u "$APP_USER" php apps/web/artisan user:has-admin >/dev/null 2>&1 || HAS_ADMIN="no"
APP_URL="$(grep '^APP_URL=' /etc/voice-guardian/web.env | cut -d= -f2-)"

echo "Déploiement terminé."
echo "Admin : ${APP_URL}/admin"

if [[ "$HAS_ADMIN" == "no" ]]; then
  echo
  echo "Aucun utilisateur admin n'a été trouvé."
  echo "Crée le premier compte admin avec :"
  echo "sudo -u $APP_USER php $APP_DIR/apps/web/artisan make:filament-user"
  echo "Ou promeus un compte existant avec :"
  echo "sudo -u $APP_USER php $APP_DIR/apps/web/artisan user:promote-admin email@exemple.com"
fi
