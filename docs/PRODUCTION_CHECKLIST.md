# Checklist de production ONEKANA

## Validation avant remise

- `composer validate --strict`, `composer test` et `composer audit` sont verts.
- Les migrations passent deux fois sur une base MariaDB vide.
- Le frontend passe lint, tests, build, audit et E2E.
- Aucun fichier `.env`, certificat, clé, sauvegarde ou identifiant réel n'est versionné.
- `AGENCY_API_AUTH_REQUIRED=true` et le diagnostic Agency ne journalise aucun secret.
- Une recette est terminée sur un environnement de staging distinct.

## Déploiement cPanel

1. Pointer le document root du sous-domaine vers `onekana-api/public`.
2. Installer avec `composer install --no-dev --optimize-autoloader`.
3. Donner au compte PHP l'écriture sur `storage/private` et `storage/cache`, jamais sur le code.
4. Renseigner l'environnement à partir de `.env.example` avec des secrets uniques.
5. Exécuter `php scripts/migrate.php`, puis `php scripts/seed-admin.php` une seule fois.
6. Supprimer `ADMIN_PASSWORD` de l'environnement dès la première connexion réussie.
7. Forcer HTTPS et vérifier `/health/live` puis `/health/ready`.
8. Tester connexion, récupération par e-mail, accès refusé et document privé.

## Finance

- Importer un plan comptable validé avant d'activer les fonctions avancées.
- Créer les journaux puis configurer ventes, créances, taxe, banque, Wallet et charges.
- Vérifier que `/health/ready` indique `finance: ok` avant `ENABLE_ADVANCED_FINANCE=true`.
- Faire valider le paramétrage et les états produits par un professionnel compétent. Le logiciel ne constitue pas à lui seul une certification de conformité SYSCOHADA.

## Sauvegarde et restauration

- Planifier chaque jour `scripts/backup-production.sh` avec les variables du cron.
- Conserver les archives chiffrées hors du dossier public et sur un stockage distinct.
- Tester `scripts/restore-production.sh` sur staging avant ouverture, puis chaque trimestre.
- Faire une sauvegarde avant chaque migration et conserver la version applicative précédente.

## Risques acceptés

- L'authentification multifacteur n'est pas incluse dans cette version. Le risque doit être réévalué avant d'ouvrir des comptes privilégiés hors de l'équipe interne.
- Les ressources Agency restent dépendantes de leur disponibilité; une panne ne doit pas bloquer les modules internes.
- Les données Agency sont en lecture seule. Les contrôles administratifs sont stockés localement.

## Retour arrière

1. Mettre l'application en maintenance et conserver les journaux.
2. Sauvegarder l'état courant avant toute manipulation.
3. Restaurer le code du dernier paquet validé.
4. Restaurer la base uniquement si la migration est incompatible et après validation de l'archive.
5. Relancer migrations, readiness et recette critique avant réouverture.
