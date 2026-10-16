# Debugging an installation

## Quick status

```bash
php bin/freesaas.php system status --format=json
php bin/freesaas.php health --format=json
curl -sS https://<host>/health
# Full ops without SSH (set app.agent_token first, then /agent/*; bootstrap also needs that token or setup.key):
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" https://<host>/agent/health
```

- `/health` — public minimal JSON (`status`, `liveness`, `readiness`, `summary`)
- `/health/detail` — full checks (login + `core.modules`); `?module=id` filter
- `/agent/*` — token API = CLI ops (see [remote-api.md](remote-api.md))
- CLI `health` — same engine as HTTP

## Schema gate / pending migrations

Symptom: after login, only a setup screen (“Run schema migrations”) — or health fails with `platform.schema_pending`.

Cause: restored vanilla likespa/oper DB (tables exist, FreeSaaS DDL not applied) or missing `base_schema_migrations` ledger.

```bash
php bin/freesaas.php migrate status --format=json
php bin/freesaas.php migrate run --format=json
php bin/freesaas.php health --format=json
# HTTP:
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" -X POST \
  "https://example.com/agent/db/migrate/run"
```

Full details: [migrations.md](migrations.md). Do not enable modules one-by-one to “fix” this — `migrate run` applies all enabled modules.

## Module blocked / enable fails

```bash
php bin/freesaas.php module list --format=json
php bin/freesaas.php module inspect <id> --format=json
```

Check `block_reason`, `requires`, `migrations_pending`. Fix deps, then:

```bash
php bin/freesaas.php migrate run --module=<id>
php bin/freesaas.php module enable <id>
```

## Validate / i18n failures

```bash
php bin/freesaas.php module validate [id] --format=json
php bin/freesaas.php locale validate --format=json
php bin/smoke.php --format=json
```

Common: prose in `hooks`, missing `lang/*.php`, route drift `routes.pages` vs bootstrap.

## Logs

- PHP/`error_log` for boot blocks (`[ModuleManager] Module 'x' blocked`)
- Migrate CLI stdout
- Layout StageTiming is human-only; prefer health/CLI for agents

## Audio workers

```bash
php bin/freesaas.php health --module=audio_pipeline --format=json
```

Prefer `cfg/modules/asr.ini` `worker_token` (UI: `/audio/settings` generates if missing). Legacy fallback: `cfg/modules/audio.ini` `worker_token`. Not `CHANGE_ME`. Jobs: `/audio/jobs/{ingest,stem,collect}` and claim `/audio/asr/claim` with `X-Audio-Token`. Deploy Mac client: [whisper-worker.md](whisper-worker.md) (`workers/whisper/`).

## Storefront / cart / booking

Symptom: empty slots, wrong catalog on a public domain, theme 404.

```bash
php bin/freesaas.php health --module=sites --format=json
# Session on admin host:
curl -sS -b jar.txt 'https://admin.example.com/sites/status?format=json'
# Public Host — open dates:
curl -sS 'https://www.example.com/cart/availability?mode=dates&product_ids=<id>'
```

Read `issues[]` from `sites.status` first (`booking_not_active`, `theme_missing`, `soft_dep_off`, …). Full runbook: [sites.md](sites.md). Slot engine: [booking.md](booking.md).
