# EuroDental Mobile API

API Laravel dédiée à l'application Ionic **angular-mobile**. Elle partage la même base PostgreSQL que `laravel-eurodental`.

## Démarrage

```bash
cd laravel-mobile
composer install
cp .env.example .env   # ou utiliser le .env fourni
php artisan key:generate
php artisan serve --port=8001
```

L'app Angular utilise `http://127.0.0.1:8001/api` par défaut.

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
