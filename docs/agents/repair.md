# Agent repair guide

When install or ops fail, prefer **machine diagnose → next command → upstream** over giving up.

## 1. Run doctor

```bash
php bin/freesaas.php doctor --format=json
# HTTP: GET /agent/doctor  + X-FreeSaaS-Agent-Token
```

Response fields:

| Field | Meaning |
|-------|---------|
| `ok` / `status` | Overall (`ok` \| `degraded` \| `fail`) |
| `error_codes` | Stable codes (see below) |
| `sections` | Per-check detail |
| `hints` | Suggested next CLI commands |
| `issue_body` | Markdown ready for a GitHub issue |

## 2. Error codes → next action

| `error_code` | Next |
|--------------|------|
| `config_missing` | `cp cfg/config.example.ini cfg/config.ini` and set DB/salts |
| `db_connect_fail` | Fix `[connect]` / start MySQL; Docker: `docker compose ps` |
| `agent_token_missing` | Set `app.agent_token` or bootstrap with `--agent-token` |
| `schema_pending` | `php bin/freesaas.php migrate run --format=json` |
| `schema_status_fail` | Check DB rights; `db check` / `db repair` |
| `module_blocked` | `module list --status=blocked`; fix `requires` / enable deps |
| `module_validate_fail` | `module validate`; fix manifest/hooks |
| `health_fail` | `health --format=json`; module-specific repair |
| `locale_fail` | `locale validate`; add missing keys in `lang/{ru,en}.php` |
| `scaffold_invalid_id` / `scaffold_exists` | Pick another module id |
| `module_not_found` | Typo or not on disk — `module install` from catalog |

Related: [debugging.md](debugging.md), [migrations.md](migrations.md), [QUICKSTART.md](QUICKSTART.md).

## 3. Suggested GitHub issue

1. Run `doctor --format=json`.
2. Copy `issue_body` into a new issue ([agent-bug template](../../.github/ISSUE_TEMPLATE/agent-bug.yml)).
3. If you have a **universal** fix: accept [CLA](../../CLA.md), open a PR with smoke/health output ([PR template](../../.github/PULL_REQUEST_TEMPLATE.md)).

Do **not** stop at «HTTP 500, contact admin» on alpha/beta modules — diagnose locally first (maturity policy on `GET /agent/modules`).

## 4. Auth reminder

Lifecycle repair uses **Surface A** (`agent_token` / CLI). Product data bugs use Surface B — [agent-surfaces.md](agent-surfaces.md).

## License note

Modified network deployments: AGPL source-disclosure — [`LICENSE`](../../LICENSE). Commercial alternative: [`COMMERCIAL-LICENSE.md`](../../COMMERCIAL-LICENSE.md).
