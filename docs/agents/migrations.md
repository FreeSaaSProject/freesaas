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
php bin/data-migrate.php --list                 # preview: what each migrator does
php bin/data-migrate.php --module=… --id=…      # one migrator (live progress)
php bin/data-migrate.php --i-understand-replace # ALL (destructive) — know what you run
# or POST /db action=data&format=json
```

`/db` lists the data catalog before the button. The run uses the **same job overlay** as dump/restore (progress bars + live log); after OK the page reloads with the report. CLI still prints `→ id` / description / `… running` / `[ok|error] …` as each migrator finishes (`--list` to preview).

### Staff identity from phpshop (oper → FreeSaaS)

**TEMP cutover pack** — not runtime. Enable `core`, `bots`, and `terminals` so `/db` data / `runAll` picks them up. After phpshop tables are dropped, delete the files + `module.json` `migrators[]` entries + matching `*.migrator.*` lang keys (see list below). Do **not** revert `Auth::hasNamespacedPermissions()` — that is permanent `module.perm` shape checking.

| Migrator | Module | What |
|----------|--------|------|
| `phpshop_users_to_base_users` (+ `_OperPermissionsRemap.php`) | core | Truncate/refill `base_users`; roles; `link_gp`→`num`; oper→`module.perm` remap |
| `remap_oper_user_permissions` | core | Remap only (no TRUNCATE) |
| `phpshop_messenger_to_base_bot_links` | bots | TG/Max → `base_bot_links` |
| `phpshop_shopusers_bracelets_to_base_staff_bracelets` | terminals | `1C_BRACELET`+`ID_USER` → `base_staff_bracelets` |

```bash
# Fix permissions on an already-migrated DB (no user replace):
php bin/data-migrate.php --module=core --id=remap_oper_user_permissions
php bin/data-migrate.php --module=terminals --id=phpshop_shopusers_bracelets_to_base_staff_bracelets
php bin/data-migrate.php --module=bots --id=phpshop_messenger_to_base_bot_links
```

**Cutoff delete checklist:** `modules/core/migrators/{phpshop_users_to_base_users,remap_oper_user_permissions,_OperPermissionsRemap}.php` + their `module.json` entries; `modules/terminals/migrators/phpshop_shopusers_bracelets_to_base_staff_bracelets.php` + entry; `modules/bots/migrators/phpshop_messenger_to_base_bot_links.php` + entry; lang `*.migrator.phpshop_*` / `core.migrator.remap_*`.

## Rules

- Runtime SQL: `base_*` only.
- New tables: `base_*`, listed only in the **owning** module’s `tables` (exclusive). Columns/indexes on a foreign table: additive migration in the feature module, **do not** add that table to its `tables[]`.
- No down-migrations. **Uninstall** (`module uninstall --confirm`) dumps exclusively owned tables then DROP + clears `base_schema_migrations` for that module; re-enable recreates empty schema via up-migrations. Shared ownership claims are skipped (never DROP). `leave_tables` modules refuse uninstall.
- After writing a new migration file: `migrate run --module=<id>`, then `health` / `smoke`.
