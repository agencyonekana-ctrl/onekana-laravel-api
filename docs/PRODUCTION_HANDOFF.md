# Remise technique ONEKANA

Ce document accompagne la remise de `onekana-business-manager` sur Vercel et de `onekana-api` sur cPanel. Il ne contient aucune valeur secrète.

## Architecture

- `admin.onekana.com`: application React/Vite déployée sur Vercel.
- `api-admin.onekana.com`: service PHP 8.2+ déployé sur cPanel, document root `public`.
- MariaDB/MySQL: source de vérité des données internes.
- Agency: service externe consommé uniquement par le serveur PHP.

## Variables Vercel

```env
VITE_API_BASE_URL=https://api-admin.onekana.com/api
VITE_ENABLE_GEOGRAPHY=true
VITE_ENABLE_ADVANCED_FINANCE=false
```

Après migration, activer temporairement `ENABLE_ADVANCED_FINANCE=true` pendant la maintenance de staging, importer le plan validé et configurer les comptes de liaison. Lorsque la readiness indique `finance: ok`, activer `VITE_ENABLE_ADVANCED_FINANCE=true` sur Vercel.

## Variables cPanel

Partir de `.env.example`. Les groupes obligatoires sont:

- application: `APP_ENV`, `APP_DEBUG`, `APP_URL`, `FRONTEND_URLS`, `FRONTEND_APP_URL`;
- base: `DB_*`;
- sessions: `JWT_SECRET`, durées et audience;
- courriel: `MAIL_*` avec `MAIL_ENABLED=true` après test SMTP;
- Agency: `AGENCY_API_*`, avec `AGENCY_API_AUTH_REQUIRED=true`;
- modules: `ENABLE_GEOGRAPHY`, `ENABLE_ADVANCED_FINANCE`;
- cron: `BACKUP_DIR`, `BACKUP_ENCRYPTION_KEY` injectées uniquement dans la tâche planifiée.

Ne jamais transmettre `JWT_SECRET`, mots de passe, clés de chiffrement ou identifiants Agency au frontend.

## Installation

```bash
composer install --no-dev --optimize-autoloader
php scripts/migrate.php
php scripts/seed-admin.php
```

Après provisionnement, retirer `ADMIN_PASSWORD`, garder `.env` hors du web et limiter ses permissions au compte cPanel. Les dossiers `storage/private` et `storage/cache` doivent être accessibles en écriture à PHP; le reste du projet doit rester en lecture seule autant que possible.

## SMTP et récupération

Configurer l'expéditeur sur un domaine vérifié, puis tester une demande pour un compte existant et une adresse inconnue. Les deux réponses visibles doivent rester identiques. Le lien expire après 30 minutes et ne fonctionne qu'une fois.

## Agency

```bash
php scripts/check-agency-api.php
```

Le diagnostic doit réussir pour les ressources activées: contacts, utilisateurs, campagnes, communes, points chauds et trajets. Une famille indisponible ne doit pas empêcher les autres écrans de fonctionner.

## Plan comptable

L'import attend un tableau JSON `accounts`, chaque élément contenant au minimum `code`, `label` et `type`, via `POST /api/accounting/accounts/import`. Les codes doivent appartenir aux classes 1 à 8. Le contenu du plan doit être fourni et validé par le responsable comptable.

## Supervision

- disponibilité processus: `GET /health/live`;
- readiness base, stockage, configuration et Finance: `GET /health/ready`;
- journaux: ne pas exposer de mot de passe, jeton, document ou donnée personnelle complète;
- sauvegarde: tâche quotidienne et test de restauration trimestriel.

Exemple de cron à adapter aux chemins cPanel:

```cron
15 2 * * * cd /home/ACCOUNT/onekana-api && /usr/bin/env bash scripts/backup-production.sh >> /home/ACCOUNT/logs/onekana-backup.log 2>&1
```

## Recette minimale

1. Connexion, renouvellement silencieux, déconnexion et récupération de mot de passe.
2. Invitation, suspension et rôle d'un administrateur secondaire.
3. Contacts, campagnes, utilisateurs et géographie Agency.
4. CRUD inventaire, parc interne, organisation et documents privés.
5. Création d'une facture de test, validation, paiement idempotent, balance équilibrée, contrepassation et clôture de période.
6. Sauvegarde chiffrée puis restauration complète sur staging.
7. Vérification des refus `401`, `403`, des limites de requêtes et de l'isolation tenant.

La procédure de retour arrière et les risques acceptés sont détaillés dans `PRODUCTION_CHECKLIST.md`.
