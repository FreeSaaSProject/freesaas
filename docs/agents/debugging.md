# Debugging an installation

## Quick status

```bash
php bin/freesaas.php system status --format=json
php bin/freesaas.php health --format=json
curl -sS https://<host>/health
# Full ops without SSH (set app.agent_token first, or POST /agent/bootstrap on virgin):
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" https://<host>/agent/health
```

- `/health` — public minimal JSON (`status`, `liveness`, `readiness`, `summary`)
- `/health/detail` — full checks (login + `core.modules`); `?module=id` filter
- `/agent/*` — token API = CLI ops (see [remote-api.md](remote-api.md))
- CLI `health` — same engine as HTTP

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

Ensure `cfg/modules/audio.ini` `worker_token` is set (not `CHANGE_ME`). Jobs: `/audio/jobs/{ingest,stem,collect}` with `X-Audio-Token`.
