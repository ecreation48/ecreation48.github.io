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

## Limites de la phase 1

Cette livraison pose le socle, les quatre modèles principaux, la gestion Filament, l'authentification de service, les heartbeats et la connexion Discord minimale. La synchronisation détaillée, les sessions vocales, les buffers audio, les signalements, les sanctions et la transcription appartiennent aux phases 2 à 5.

Voir [l'architecture](docs/architecture.md) et [l'API interne](docs/openapi.yaml).
