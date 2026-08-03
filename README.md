# Voice Guardian

Socle d'une plateforme de modération vocale Discord multi-bots. Le dépôt contient l'API Laravel 12/Filament 3, le gestionnaire Discord TypeScript, la transcription audio et l'installation Linux avec PostgreSQL, Redis, Nginx et systemd.

## Démarrage

Prérequis serveur : Debian 13/Trixie ou Ubuntu 24.04, accès `root`, DNS optionnel.

```bash
apt-get update
apt-get install -y sudo git ca-certificates curl unzip

git clone https://github.com/ecreation48/ecreation48.github.io.git /tmp/voice-guardian-install
cd /tmp/voice-guardian-install

REPO_URL=https://github.com/ecreation48/ecreation48.github.io.git bash scripts/linux/install-server.sh
```

L'application est installée dans `/opt/voice-guardian`. L'administration est disponible sur `http://IP_DU_SERVEUR/admin`.

Créez le premier compte admin :

```bash
sudo -u voiceguardian php /opt/voice-guardian/apps/web/artisan make:filament-user
```

Pour mettre à jour :

```bash
sudo bash /opt/voice-guardian/scripts/linux/deploy.sh
```

Le guide complet est disponible dans [docs/deployment-linux.md](docs/deployment-linux.md).

### Authentification SSO Authentik

Le login Filament peut afficher un bouton **Connexion SSO** via Authentik. À configurer dans `/etc/voice-guardian/web.env` :

```dotenv
AUTHENTIK_SSO_ENABLED=true
AUTHENTIK_BASE_URL=https://auth.kinoah2k.com:4443
AUTHENTIK_ISSUER_URL=https://auth.kinoah2k.com:4443/application/o/SLUG_APPLICATION/
AUTHENTIK_CLIENT_ID=...
AUTHENTIK_CLIENT_SECRET=...
AUTHENTIK_TOKEN_AUTH_METHOD=client_secret_post
```

Callback à déclarer dans Authentik : `https://votre-domaine.fr/auth/sso/callback`.

Quand le SSO est activé, `/admin/login` redirige automatiquement vers Authentik. Le login local reste disponible sur `/login/manuel`.

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

### Installation complète Debian/Ubuntu

Ces commandes sont prévues pour un VPS Debian 13/Trixie ou Ubuntu 24.04, connecté en `root`.

```bash
apt-get update
apt-get install -y sudo git ca-certificates curl unzip

git clone https://github.com/ecreation48/ecreation48.github.io.git /tmp/voice-guardian-install
cd /tmp/voice-guardian-install

REPO_URL=https://github.com/ecreation48/ecreation48.github.io.git bash scripts/linux/install-server.sh
```

Le script installe Nginx, PHP-FPM, les extensions PHP nécessaires (`pgsql`, `xml`, `mbstring`, `curl`, `zip`, `bcmath`, `intl`, `redis`), Composer, Node.js 24, PostgreSQL, Redis, les services systemd et l’application dans `/opt/voice-guardian`.

Si vous avez déjà cloné le projet et devez relancer manuellement les étapes Laravel :

```bash
apt-get update
apt-get install -y php-fpm php-cli php-pgsql php-xml php-mbstring php-curl php-zip php-bcmath php-intl php-redis unzip curl git

cd /opt/voice-guardian/apps/web
sudo -u voiceguardian composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
sudo -u voiceguardian php artisan key:generate --force
sudo -u voiceguardian php artisan migrate --force
sudo -u voiceguardian php artisan filament:assets
sudo -u voiceguardian php artisan optimize:clear
sudo -u voiceguardian php artisan config:cache
sudo -u voiceguardian php artisan route:cache
sudo -u voiceguardian php artisan view:cache
```

Si la page affiche encore la page par défaut Nginx, activez le vhost Voice Guardian :

```bash
cat > /etc/nginx/sites-available/voice-guardian.conf <<'EOF'
server {
    listen 80 default_server;
    server_name _;

    root /opt/voice-guardian/apps/web/public;
    index index.php;

    client_max_body_size 128m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }

    location ~ /\. {
        deny all;
    }
}
EOF

rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/voice-guardian.conf /etc/nginx/sites-enabled/voice-guardian.conf
nginx -t
systemctl reload nginx
```

Créez ensuite le premier compte admin :

```bash
sudo -u voiceguardian php /opt/voice-guardian/apps/web/artisan make:filament-user
```

L’admin est disponible sur :

```text
http://IP_DU_SERVEUR/admin
```

Pour installer Whisper local :

```bash
apt-get update
apt-get install -y build-essential cmake git ffmpeg
bash /opt/voice-guardian/scripts/linux/install-whisper.sh
systemctl restart voice-guardian-discord
```

Commandes utiles :

```bash
/opt/voice-guardian/scripts/linux/voice-guardian.sh status
/opt/voice-guardian/scripts/linux/voice-guardian.sh logs
journalctl -u voice-guardian-discord -f
tail -f /opt/voice-guardian/apps/web/storage/logs/laravel.log
```

Diagnostic rapide en cas de 500 :

```bash
tail -n 120 /opt/voice-guardian/apps/web/storage/logs/laravel.log
cd /opt/voice-guardian/apps/web
sudo -u voiceguardian php artisan about
sudo -u voiceguardian php artisan migrate:status
ls -la /opt/voice-guardian/apps/web/storage /opt/voice-guardian/apps/web/bootstrap/cache
```

Pour HTTPS avec un domaine :

```bash
apt-get update
apt-get install -y certbot python3-certbot-nginx
certbot --nginx -d votre-domaine.fr
```

Les fichiers utiles sont :

- `scripts/linux/install-server.sh`
- `scripts/linux/deploy.sh`
- `scripts/linux/install-whisper.sh`
- `scripts/linux/voice-guardian.sh`
- `deploy/linux/systemd/*`
- `deploy/linux/nginx/voice-guardian.conf`
- `deploy/linux/*.env.example`
