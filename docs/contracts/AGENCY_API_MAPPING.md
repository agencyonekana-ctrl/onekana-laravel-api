# Agency API Mapping

Ce dossier conserve le contrat OpenAPI fourni pour l'API Onekana Agency et le mapping utilise par le backend admin.

## Principe

Le back office admin ne consomme pas l'API Agency directement. Il appelle uniquement `onekana-api`, qui normalise les donnees et masque les details d'authentification serveur.

## Etat de l'integration

Le mode temporaire utilise `https://manager.onekana-agency.com/api` sans authentification Agency. Le JWT administrateur ONEKANA reste obligatoire pour joindre ce proxy. Seules les lectures `users`, `campaigns` et `contacts` sont autorisees dans ce mode; les modifications distantes restent bloquees.

Les routes geographiques ne sont pas actives tant que le fournisseur retourne `404`. Lorsqu'un mecanisme de service sera fourni, activer `AGENCY_API_AUTH_REQUIRED=true`, renseigner les secrets uniquement dans le `.env` non versionne, puis executer `php scripts/check-agency-api.php`.

## Mapping

| Back office admin | API Agency source |
| --- | --- |
| `/api/agency/profile` | `/auth.php?action=profile` |
| `/api/agency/users` | `/users.php?action=get_all` |
| `/api/agency/users/{id}` | `/users.php?action=get_by_id&id={id}` |
| `/api/agency/campaigns` | `/campaigns.php?action=get_all` |
| `/api/agency/campaigns/{id}` | `/campaigns.php?action=get_by_id&id={id}` |
| `/api/agency/contacts` | `/contacts.php` |
| `/api/agency/contacts/{id}` | `/contacts.php?id={id}` |
| `/api/agency/geographic/communes` | `/geographic.php?entity=communes&action=get_all` |
| `/api/agency/geographic/points-chauds` | `/geographic.php?entity=points_chauds&action=get_all` |
| `/api/agency/geographic/trajets` | `/geographic.php?entity=trajets&action=get_all` |
| `/api/agency/summary` | Synthese composee par `onekana-api` |

## Securite

Les identifiants du compte service Agency restent uniquement dans les variables d'environnement serveur. Aucun token Agency ne doit etre retourne au frontend ni stocke dans le depot.
