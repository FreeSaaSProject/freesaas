# Migrations

Schema / data migrators. Full DB hub (backups, CHECK/REPAIR/OPTIMIZE, expert mode): [`db-backup.md`](db-backup.md).

## Agent-first repair (restored / vanilla likespa DB)

When UI shows the schema setup gate (“Run schema migrations”), the DB has pending DDL or a missing ledger. Agents should **not** poke `/modules` per module — use one of:

```bash
# Prefer CLI on the box
php bin/freesaas.php migrate status --format=json
php bin/freesaas.php migrate run --format=json
php bin/freesaas.php health --format=json   # platform.schema_pending must be ok

# Shared hosting (app.agent_token)
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/db/migrate"
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/db/migrate/run"

# Session twin (same as human /db; needs login + core.db)
curl -sS -b jar.txt 'https://example.com/db?format=json'
curl -sS -b jar.txt -X POST -d 'action=schema&format=json' \
  'https://example.com/db'
```

Success signals:

- `setup.needed` / `schema_needed` → `false`
- `pending` → `[]`
- HTTP 200 and `ok: true` on migrate status/run
- Health check `platform.schema_pending` → `ok`

While setup is incomplete, authenticated pages except `/db`, `/modules`, `/logout`, `/locale`, and `/agent/*` show a blocking gate.

## Schema (DDL)

Files: `modules/<id>/migrations/NNN_name.php` returning `function (PDO $pdo): void`.

```bash
php bin/freesaas.php migrate status [--module=id] --format=json
php bin/freesaas.php migrate run [--module=id] --format=json
# legacy: php bin/schema-migrate.php
```

UI: **`/db`** — lists pending migrations and applies **all** enabled modules in one click (`core` first). Per-module buttons on `/modules` remain for targeted fixes.

Enable still runs schema for that module first:

```bash
php bin/freesaas.php module enable <id>
```

Ledger: `base_schema_migrations`. Idempotent where possible (`CREATE IF NOT EXISTS`, column probes).

### Product JSON (`core.db.*`)

Declared in `modules/core/module.json` → `agent.ops`. Same routes as UI; `?format=json` or `Accept: application/json`. See [module-agent-api.md](module-agent-api.md).

| Op | Method | Body |
|----|--------|------|
| `core.db.status` | `GET /db` | — |
| `core.db.run_schema` | `POST /db` | `action=schema` |
| `core.db.run_data` | `POST /db` | `action=data` |
| `core.db.dump` | `POST /db` | `action=db_dump` |
| `core.db.restore` | `POST /db` | `action=db_restore&file=…` |

Platform twin (token, no session): `GET /agent/db/migrate`, `POST /agent/db/migrate/run` — see [remote-api.md](remote-api.md) and [db-backup.md](db-backup.md).

## Data migrators

Optional one-shot scripts under `modules/*/migrators/`, declared in `module.json` → `migrators` (often replace/seed into `base_*`). **Not required** when restoring a populated likespa dump — schema alone is enough to unlock the app.

```bash
php bin/data-migrate.php   # destructive replace — know what you run
# or POST /db action=data&format=json
```

## Rules

- Runtime SQL: `base_*` only.
- New tables/columns: `base_*`, listed in the owning module’s `tables`.
- No down-migrations. **Uninstall** (`module uninstall --confirm`) dumps owned tables then DROP + clears `base_schema_migrations` for that module; re-enable recreates empty schema via up-migrations. `leave_tables` modules refuse uninstall.
- After writing a new migration file: `migrate run --module=<id>`, then `health` / `smoke`.
