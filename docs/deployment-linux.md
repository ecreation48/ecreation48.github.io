# Déploiement Linux

Ce guide prépare un serveur Linux pour Voice Guardian avec Laravel/Filament, PostgreSQL, Redis, Nginx, le worker Discord Node.js et la transcription locale Whisper.

La cible recommandée est Ubuntu 24.04 LTS ou Debian 13/Trixie. Le projet demande PHP 8.3 minimum et Node.js 22.

## Installation complète

Connectez-vous en SSH sur le serveur, puis lancez :

```bash
sudo apt-get update
sudo apt-get install -y git ca-certificates
git clone https://github.com/ecreation48/ecreation48.github.io.git /tmp/voice-guardian-install
cd /tmp/voice-guardian-install
sudo REPO_URL=https://github.com/ecreation48/ecreation48.github.io.git bash scripts/linux/install-server.sh
```

Le script installe :

- Nginx
- PHP-FPM disponible dans la distribution et extensions Laravel
- Composer
- Node.js 22
- PostgreSQL
- Redis
- les services systemd du projet

L’application est installée dans `/opt/voice-guardian`.

## Configuration

Les fichiers de configuration de production sont :

```bash
/etc/voice-guardian/web.env
/etc/voice-guardian/worker.env
```

À vérifier après installation :

```bash
sudo nano /etc/voice-guardian/web.env
sudo nano /etc/voice-guardian/worker.env
```

Les valeurs importantes sont :

```dotenv
APP_URL=https://votre-domaine.fr
WORKER_SERVICE_TOKEN=le-meme-token-dans-les-deux-fichiers
INTERNAL_API_URL=http://127.0.0.1/api/v1/internal
LIVE_AUDIO_PORT=8787
```

Après modification :

```bash
sudo bash /opt/voice-guardian/scripts/linux/deploy.sh
sudo systemctl restart voice-guardian-discord voice-guardian-queue nginx
```

## Créer le compte admin

```bash
sudo -u voiceguardian php /opt/voice-guardian/apps/web/artisan make:filament-user
```

L’admin est disponible sur :

```text
http://IP_DU_SERVEUR/admin
```

ou, après DNS :

```text
https://votre-domaine.fr/admin
```

## HTTPS

Après avoir pointé le DNS vers le serveur :

```bash
sudo apt-get install -y certbot python3-certbot-nginx
sudo certbot --nginx -d votre-domaine.fr
```

Vérifiez ensuite :

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## Installer Whisper local

Installation CPU :

```bash
sudo bash /opt/voice-guardian/scripts/linux/install-whisper.sh
```

Le modèle par défaut est `small`. Pour un modèle plus rapide :

```bash
sudo MODEL=base bash /opt/voice-guardian/scripts/linux/install-whisper.sh
```

Pour un serveur NVIDIA/CUDA, après installation des drivers CUDA :

```bash
sudo USE_CUDA=true MODEL=small bash /opt/voice-guardian/scripts/linux/install-whisper.sh
sudo sed -i 's/WHISPER_CPP_USE_GPU=false/WHISPER_CPP_USE_GPU=true/g' /etc/voice-guardian/worker.env /etc/voice-guardian/web.env
sudo systemctl restart voice-guardian-discord
```

Configuration attendue :

```dotenv
TRANSCRIPTION_PROVIDER=command
TRANSCRIPTION_ENGINE=whisper.cpp
TRANSCRIPTION_LANGUAGE=fr
WHISPER_CPP_BINARY=/opt/whisper.cpp/build/bin/whisper-cli
WHISPER_CPP_MODEL=/opt/whisper.cpp/models/ggml-small.bin
WHISPER_CPP_USE_GPU=false
TRANSCRIPTION_COMMAND='node /opt/voice-guardian/scripts/transcribe-whisper-json.mjs {file}'
```

## Déployer une mise à jour

```bash
sudo bash /opt/voice-guardian/scripts/linux/deploy.sh
```

Ce script fait :

- `git pull --ff-only`
- `composer install --no-dev`
- `npm ci`
- build du worker Discord
- migrations Laravel
- cache Laravel
- redémarrage des services applicatifs

## Exploitation

Statut :

```bash
sudo /opt/voice-guardian/scripts/linux/voice-guardian.sh status
```

Logs en direct :

```bash
sudo /opt/voice-guardian/scripts/linux/voice-guardian.sh logs
```

Redémarrer :

```bash
sudo /opt/voice-guardian/scripts/linux/voice-guardian.sh restart
```

Stopper :

```bash
sudo /opt/voice-guardian/scripts/linux/voice-guardian.sh stop
```

Démarrer :

```bash
sudo /opt/voice-guardian/scripts/linux/voice-guardian.sh start
```

Logs séparés :

```bash
sudo journalctl -u voice-guardian-discord -f
sudo journalctl -u voice-guardian-queue -f
sudo tail -f /opt/voice-guardian/apps/web/storage/logs/laravel.log
```

## Vérifications rapides

```bash
curl -I http://127.0.0.1/admin
sudo systemctl is-active nginx postgresql redis-server voice-guardian-discord voice-guardian-queue
sudo -u voiceguardian php /opt/voice-guardian/apps/web/artisan migrate:status
```

Test Whisper :

```bash
sudo -u voiceguardian \
  WHISPER_CPP_BINARY=/opt/whisper.cpp/build/bin/whisper-cli \
  WHISPER_CPP_MODEL=/opt/whisper.cpp/models/ggml-small.bin \
  WHISPER_CPP_USE_GPU=false \
  TRANSCRIPTION_LANGUAGE=fr \
  node /opt/voice-guardian/scripts/transcribe-whisper-json.mjs /chemin/vers/audio.mp3
```
