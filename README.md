# EuroDental Mobile API

API Laravel dédiée à l'application Ionic **angular-mobile**. Elle utilise la **même base MySQL** que `laravel-eurodental` (schéma géré par eurodental uniquement — pas de migrations dans ce projet).

## Démarrage

```bash
cd laravel-mobile
composer install
cp .env.example .env   # ou utiliser le .env fourni — DB MySQL comme eurodental
php artisan key:generate
php artisan serve --port=8001
```

Ne pas exécuter `php artisan migrate` ici : les tables existent déjà via `laravel-eurodental`.

L'app Angular utilise `http://127.0.0.1:8001/api` par défaut.

## Déploiement (GitHub Actions → Hostinger)

Workflow : `.github/workflows/deploy.yml` (push sur `main` ou **Run workflow** manuel).

### Secrets GitHub requis

| Secret | Exemple / note |
|--------|----------------|
| `SSH_HOST` | IP ou hostname Hostinger |
| `SSH_PORT` | ex. `65002` |
| `SSH_USER` | ex. `u629839892` |
| `SSH_PASSWORD` | Mot de passe SSH Hostinger |
| `DEPLOY_PATH` | `/home/u629839892/domains/mobile.eurodental.ma/laravel-mobile` |
| `PHP_BIN` | `/usr/bin/php` |
| `APP_KEY` | `base64:...` (`php artisan key:generate --show`) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL production (même base que eurodental) |
| `CORS_ALLOWED_ORIGINS` | Origines de l’app Ionic |
| `APP_URL` | optionnel — défaut `https://mobile.eurodental.ma` |
| `ASSET_URL` | optionnel — défaut `https://eurodental.ma` |
| `SSH_PRIVATE_KEY` | optionnel — non requis si `SSH_PASSWORD` est défini |

### hPanel (une fois)

1. Créer le dossier de déploiement (ou laisser le workflow le créer).
2. **Document root** du site `mobile.eurodental.ma` → `.../laravel-mobile/public` (pas seulement `public_html`).

### Après deploy

- `https://mobile.eurodental.ma/up` — health check Laravel
- `POST https://mobile.eurodental.ma/api/login` — test API

## Authentification

- `POST /api/login` — `{ email, password }` → `{ token, user }`
- `POST /api/logout` — Bearer token
- `GET /api/me` — Bearer token

## Tâches (technicien / aide, hors déploiements)

- `GET /api/tasks/today` — tâches du jour
- `GET /api/tasks/{id}` — détail
- `GET /api/tasks/{id}/events` — timeline, garantie, propositions
- Actions : `start-route`, `end-route`, `start-visit`, `pause-visit`, `resume-visit`, `finish-visit`, `finish`, `payment`, etc.

## Assets (images)

Configurer `ASSET_URL` vers l'URL publique de `laravel-eurodental` (ex. `http://127.0.0.1:8000`) pour les URLs `storage/`.
