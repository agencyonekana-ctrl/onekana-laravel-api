# ONEKANA API

Service PHP 8.2 du back office interne ONEKANA, concu pour MySQL/MariaDB et un deploiement cPanel.

## Installation locale

```bash
composer install
cp .env.example .env
php scripts/migrate.php
php scripts/seed-admin.php
php -S 127.0.0.1:8000 -t public
```

Apres le premier provisionnement, retirer le mot de passe initial du fichier d'environnement.

## Sessions et securite

- access token court conserve uniquement en memoire par le navigateur;
- refresh token rotatif en cookie `HttpOnly`, `Secure` en production et `SameSite=Strict`;
- verification de la signature, de l'expiration, de l'emetteur et de l'audience;
- isolation tenant, permissions lecture/gestion et limitation des requetes sensibles;
- documents prives servis uniquement apres controle de session et de tenant;
- reponses d'erreur JSON, en-tetes de securite, identifiant de requete et journal d'audit.

Les secrets, identifiants Agency et fichiers `.env` ne doivent jamais etre versions ni transmis au frontend.

## Verification

```bash
composer validate --strict
composer test
composer audit
php scripts/check-agency-api.php
```

Le diagnostic Agency doit etre execute uniquement dans un environnement autorise. Il ne journalise aucun secret.

## Deploiement

Le document root du sous-domaine doit pointer vers `public`. Installer les dependances sans outils de developpement, appliquer les migrations, puis verifier `/health/live` et `/health/ready`.

La procedure complete, la sauvegarde et le retour arriere sont decrits dans `docs/PRODUCTION_CHECKLIST.md`.
