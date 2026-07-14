# Database

Le schema est gere par les migrations versionnees de `src/Database/Migrations`.

Appliquer les migrations:

```bash
php scripts/migrate.php
```

Ne pas modifier directement la base de production. Effectuer une sauvegarde avant chaque migration.
