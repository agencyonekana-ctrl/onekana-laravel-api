# Remise en conformite de l'API Agency

Base attendue: `https://manager.onekana-agency.com/api`

Avant activation de l'integration ONEKANA, le serveur Agency doit fournir les routes suivantes:

- `POST /auth.php?action=login`
- `GET /auth.php?action=profile`
- `GET /users.php?action=get_all`
- `GET /campaigns.php?action=get_all`
- `GET /contacts.php`
- `GET /geographic.php?entity=communes&action=get_all`
- `GET /geographic.php?entity=points_chauds&action=get_all`
- `GET /geographic.php?entity=trajets&action=get_all`

Toutes les routes de donnees doivent exiger `Authorization: Bearer <token>` et retourner `401` ou `403` sans ce jeton. Les donnees CRM et les utilisateurs ne doivent jamais etre exposes publiquement.

Le responsable du serveur Agency doit egalement publier une documentation qui declare cette meme base et ces seules routes. Une fois le deploiement effectue, l'equipe ONEKANA utilise un compte de service dedie dans `AGENCY_API_EMAIL` et `AGENCY_API_PASSWORD`; ces valeurs ne sont jamais ajoutees au depot.
