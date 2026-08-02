# Voice Guardian

Socle de la phase 1 d'une plateforme de modération vocale Discord multi-bots. Le dépôt contient l'API Laravel 12/Filament 3, le gestionnaire Discord TypeScript et l'infrastructure PostgreSQL, Redis, MinIO et Nginx.

## Démarrage

Prérequis : Docker avec Compose v2.

```bash
cp .env.example .env
# Remplacer les mots de passe et WORKER_SERVICE_TOKEN (au moins 24 caractères)
./scripts/install.sh
```

L'administration est exposée sur `http://localhost:8080/admin`. Créez le premier compte avec `docker compose exec laravel php artisan make:filament-user`.

## Développement

```bash
composer install --working-dir=apps/web
cp .env.example apps/web/.env
php apps/web/artisan key:generate
php apps/web/artisan test
npm install
npm run typecheck
npm test
```

Le worker interroge l'API interne, acquiert `discord-bot-lock:{uuid}` avec une durée de vie, puis récupère le token chiffré via une route protégée. Seul le propriétaire du verrou le renouvelle ou le libère grâce à des scripts Lua atomiques. Les tokens sont masqués dans les modèles, ne figurent pas dans la liste API et ne sont jamais journalisés.

## Transcription audio

Le worker peut transcrire automatiquement les extraits audio créés lors d’un signalement.

Option API OpenAI :

```bash
TRANSCRIPTION_PROVIDER=openai
OPENAI_API_KEY=sk-...
OPENAI_TRANSCRIPTION_MODEL=whisper-1
TRANSCRIPTION_LANGUAGE=fr
```

Option moteur local, par exemple whisper.cpp ou un script interne :

```bash
TRANSCRIPTION_PROVIDER=command
TRANSCRIPTION_ENGINE=whisper.cpp
TRANSCRIPTION_LANGUAGE=fr
WHISPER_CPP_BINARY=/opt/whisper.cpp/build/bin/whisper-cli
WHISPER_CPP_MODEL=/opt/whisper.cpp/models/ggml-small.bin
WHISPER_CPP_USE_GPU=false
TRANSCRIPTION_COMMAND='node /opt/voice-guardian/scripts/transcribe-whisper-json.mjs {file}'
```

La commande locale doit écrire un JSON sur stdout :

```json
{"text":"Bonjour","language":"fr","segments":[{"start_seconds":0,"end_seconds":1.2,"text":"Bonjour"}]}
```

Installation Linux conseillée pour whisper.cpp :

```bash
sudo apt-get update
sudo apt-get install -y build-essential cmake git
sudo git clone https://github.com/ggml-org/whisper.cpp /opt/whisper.cpp
cd /opt/whisper.cpp
cmake -B build
cmake --build build -j
./models/download-ggml-model.sh small
```

Pour un serveur avec GPU NVIDIA, compilez whisper.cpp avec CUDA selon la documentation officielle, puis gardez le même wrapper `scripts/transcribe-whisper-json.mjs`.

## Limites de la phase 1

Cette livraison pose le socle, les quatre modèles principaux, la gestion Filament, l'authentification de service, les heartbeats et la connexion Discord minimale. La synchronisation détaillée, les sessions vocales, les buffers audio, les signalements, les sanctions et la transcription appartiennent aux phases 2 à 5.

Voir [l'architecture](docs/architecture.md) et [l'API interne](docs/openapi.yaml).

## Déploiement Linux

Une version prête pour VPS Linux est disponible dans [docs/deployment-linux.md](docs/deployment-linux.md).

Les fichiers utiles sont :

- `scripts/linux/install-server.sh`
- `scripts/linux/deploy.sh`
- `scripts/linux/install-whisper.sh`
- `scripts/linux/voice-guardian.sh`
- `deploy/linux/systemd/*`
- `deploy/linux/nginx/voice-guardian.conf`
- `deploy/linux/*.env.example`
