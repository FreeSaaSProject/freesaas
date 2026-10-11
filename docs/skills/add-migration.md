# Skill: add-migration

```bash
php bin/freesaas.php module add-migration <id> <slug> --format=json
# edit modules/<id>/migrations/NNN_<slug>.php (additive, IF NOT EXISTS / column checks)
php bin/freesaas.php migrate run --module=<id> --format=json
php bin/freesaas.php health --module=<id> --format=json
```

Details: [`../agents/migrations.md`](../agents/migrations.md).
