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

## Centre de validation

Le module est active avec `ENABLE_APPROVAL_CENTER=true` apres migration et recette. Il conserve localement les assignations, commentaires, decisions et historiques sans modifier les donnees Agency.

Dans cette version, les donnees Agency restent strictement en lecture seule et `AGENCY_API_AUTH_REQUIRED=false` suit le contrat actuel du fournisseur. Cette valeur devra etre revue lorsque son mecanisme d'authentification administrative sera disponible.

Pour actualiser la file de validation :

```bash
php scripts/sync-approval-cases.php
```

Sur cPanel, planifier cette commande toutes les cinq minutes. Elle est idempotente : les memes ressources sont indexees sans creer de doublons. Les delais de traitement sont configurables depuis les parametres du back office.

## Deploiement

Le document root du sous-domaine doit pointer vers `public`. Installer les dependances sans outils de developpement, appliquer les migrations, puis verifier `/health/live` et `/health/ready`.

La remise cPanel/Vercel est décrite dans `docs/PRODUCTION_HANDOFF.md`. La sauvegarde, les risques acceptés et le retour arrière sont détaillés dans `docs/PRODUCTION_CHECKLIST.md`.
