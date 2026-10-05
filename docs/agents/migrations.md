# Migrations

## Schema (DDL)

Files: `modules/<id>/migrations/NNN_name.php` returning `function (PDO $pdo): void`.

```bash
php bin/freesaas.php migrate status [--module=id] --format=json
php bin/freesaas.php migrate run [--module=id] --format=json
# legacy: php bin/schema-migrate.php
```

Enable runs schema for that module first:

```bash
php bin/freesaas.php module enable <id>
```

Ledger: `base_schema_migrations`. Idempotent where possible (`CREATE IF NOT EXISTS`, column probes).

## Data migrators

Optional one-shot scripts under `modules/*/migrators/`, declared in `module.json` → `migrators` (often replace/seed into `base_*`).

```bash
php bin/data-migrate.php   # destructive replace — know what you run
```

## Rules

- Runtime SQL: `base_*` only.
- New tables/columns: `base_*`, listed in the owning module’s `tables`.
- No down/rollback yet; uninstall = disable-only.
