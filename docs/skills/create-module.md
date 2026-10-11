# Skill: create-module

```bash
php bin/freesaas.php module create <id> --title='Human Title' --format=json
# edit modules/<id>/…
php bin/freesaas.php module enable <id> --accept-risk --format=json
php bin/freesaas.php module validate <id> --format=json
php bin/freesaas.php health --module=<id> --format=json
php bin/build-modules-catalog.php   # if shipping in monorepo
```

Details: [`../agents/create-module.md`](../agents/create-module.md). Rules: `base_*` tables, i18n both locales, EventBus not foreign `pages/`.
