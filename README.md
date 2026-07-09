# ONEKANA API

API PHP 8.2 native pour le back office ONEKANA, compatible cPanel.

## Prérequis

- PHP 8.2+
- Composer
- MySQL ou MariaDB
- Extensions PHP: `pdo_mysql`, `openssl`, `mbstring`, `json`, `fileinfo`

## Installation

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Configurer `.env`, puis exécuter:

```bash
php scripts/migrate.php
php scripts/seed-admin.php
```

Après le premier seed, supprimer `ADMIN_PASSWORD` du `.env`. Le mot de passe reste uniquement sous forme de hash en base.

## Déploiement cPanel

Pointer le sous-domaine API vers:

```text
onekana-api/public
```

Le fichier `.env`, `src`, `scripts`, `vendor` et les fichiers de configuration doivent rester hors du document root public.

## Variables importantes

- `APP_URL`: URL publique de l’API.
- `DB_*`: connexion MySQL/MariaDB.
- `FRONTEND_URLS`: domaines autorisés pour CORS.
- `SYSTEM_API_TOKEN`: token privé pour `/api/system/*`.
- `ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD`: provisionnement initial admin.
- `JWT_SECRET`: secret long et unique de signature JWT.
- `JWT_TTL`: durée des access tokens en minutes.

## Endpoints principaux

- `POST /api/auth/login`
- `GET /api/auth/me`
- `POST /api/auth/refresh`
- `POST /api/auth/logout`
- `GET /api/system/*` avec header `X-System-Token`
- Ressources admin `/api/*` avec header `Authorization: Bearer <token>`

## Développement

```bash
composer install
php scripts/migrate.php
php scripts/seed-admin.php
php -S 127.0.0.1:8000 -t public
```

Tests:

```bash
vendor/bin/phpunit
```
