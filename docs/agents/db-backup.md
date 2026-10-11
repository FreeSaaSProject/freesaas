# Database hub (`/db`)

Schema migrations, optional data migrators, SQL backups of FreeSaaS-owned tables, and CHECK/REPAIR/OPTIMIZE.

**Auth:** `/db` requires a staff session + permission `core.db` (not public). Greenfield / lost-login: CLI `migrate run` / `bootstrap` / `rescue`, or HTTP `/agent/*` with `agent_token` (virgin bootstrap) / `var/setup.key` (rescue). Destructive data migrators: prefer CLI `php bin/data-migrate.php --module=… --id=…` (full run needs `--i-understand-replace`).

## Human UI

- Path: **`/db`** (rail: Settings → Database)
- Permission: **`core.db`** (session required)
- Modes (per-user pref `db_tools_mode`):
  - **normal** (default): dump/restore/maintenance on owned tables only (`module.json` → `tables`); no table picker; no DROP
  - **expert**: all tables in the connect DB; selection; DROP (UI only) with confirm; banner warns to return to normal

Maintenance probe (CHECK + free-space hints) is **on demand** via «Probe tables» / job `diagnose` — not on plain GET. Results stay in the session until mode switch or a new probe.

Dumps write plain `.sql` with large multi-row `INSERT … VALUES` (~1 MiB) ending in Sypex `\t;` terminators, then a **single-member** `.sql.gz` at **gzip level 5**; the temporary `.sql` is deleted after compress.

Gzip level 5 ≈ good ratio/speed (1=fastest/largest, 9=slowest/smallest). A 161 MB `.gz` from ~900 MB–1 GB SQL is normal: InnoDB on-disk size includes indexes/free pages that are not in the dump.

Restore: inflate once to a temp seekable `.sql`, then `fseek` + SQL. Session: `UNIQUE_CHECKS=0`, `DISABLE KEYS`, reconnect on MySQL 2002/gone-away. Fast `\t;` splitter (quote-aware fallback for foreign dumps). Temp inflate file is removed when the job finishes or is cancelled.

Fetch a remote dump: on prod `/db` use **Copy link** (builds a short-lived `GET /agent/db/dump/file?file=NAME.sql.gz&exp=…&sig=…` when `app.agent_token` is set — HMAC, not the raw token), paste into the other instance «Fetch dump by URL» (saved as `…-from-{host}.sql.gz`). Curl with header: `curl -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://prod/agent/db/dump/file?file=NAME.sql.gz" -o NAME.sql.gz`.

Dumps live under `var/db_dumps/` (gitignored via `var/`).

## Agent / CLI (owned scope only)

Agents never get expert mode or `DROP TABLE`.

```bash
php bin/freesaas.php migrate status --format=json
php bin/freesaas.php migrate run --format=json
php bin/freesaas.php db status --format=json
php bin/freesaas.php db dump [--label=name] --format=json
php bin/freesaas.php db dumps --format=json
php bin/freesaas.php db restore --file=NAME.sql.gz --format=json
php bin/freesaas.php db delete-dump --file=NAME.sql.gz --format=json
php bin/freesaas.php db check|repair|optimize --format=json
```

Shared hosting (token):

```bash
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/db"
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/db/migrate"
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/db/migrate/run"
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/db/dump?label=pre-change"
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/db/restore?file=….sql.gz"
```

Session twin (login + `core.db`): `GET/POST /db?format=json` with `action=schema|data|db_dump|db_restore|…`.

Product ops: `modules/core/module.json` → `agent.ops` (`core.db.*`).

## Rules

- Runtime SQL elsewhere still: `base_*` only.
- Dump/restore/maintenance via this hub may touch foreign tables **only** in expert UI.
- Restore rejects SQL that references **invalid** table names only. Full dumps into empty DB are allowed (declared module tables + any valid name in the dump); normal-mode owned filter no longer blocks restore.
- No `db drop` in CLI or `/agent/*`.
