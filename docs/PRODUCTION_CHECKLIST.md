# Checklist de production ONEKANA API

## Avant deploiement

- Executer `composer validate --strict`, `composer test` et `composer audit`.
- Utiliser une base et des secrets distincts pour staging et production.
- Configurer `APP_ENV=production`, `APP_DEBUG=false` et une URL HTTPS.
- Generer `JWT_SECRET` et `SYSTEM_API_TOKEN` avec au moins 32 octets aleatoires.
- Limiter `FRONTEND_URLS` au domaine admin officiel.
- Garder `AGENCY_API_AUTH_REQUIRED=true`; ne jamais deployer le mode public temporaire.
- Laisser `ADMIN_PASSWORD` vide apres le provisionnement initial.
- Verifier que l'authentification multifacteur est activee avant l'ouverture hors pilote.

## Deploiement cPanel

1. Pointer `api-admin.onekana.com` vers le dossier `public` du projet.
2. Installer avec `composer install --no-dev --optimize-autoloader`.
3. Executer `php scripts/migrate.php` une seule fois par version deployee.
4. Activer HTTPS force depuis cPanel.
5. Verifier `/health/live` puis `/health/ready`.
6. Tester une connexion, un acces refuse et le telechargement d'un document prive.

## Sauvegarde et restauration

- Planifier chaque jour `scripts/backup-production.sh` avec des variables injectees par cPanel.
- Conserver les sauvegardes chiffrees hors du dossier public et sur un stockage distant.
- Tester une restauration sur staging avant l'ouverture, puis chaque trimestre.
- Documenter le temps de restauration et la derniere sauvegarde valide.

## Lancement pilote

- Activer dashboard, contacts, campagnes en lecture, utilisateurs, inventaire et organisation.
- Garder la geographie masquee tant que les ressources distantes sont indisponibles.
- Garder Wallet et Comptabilite masques tant que les invariants comptables ne sont pas garantis.
- Ne pas autoriser les ecritures Agency sans contrat et permission dediee.

## Retour arriere

- Conserver le build et le code serveur precedents.
- Faire une sauvegarde avant chaque migration.
- En cas d'echec, restaurer le code precedent puis la base uniquement si la migration est incompatible.
- Ne jamais improviser une suppression de tables en production.
