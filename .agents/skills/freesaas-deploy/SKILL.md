---
name: freesaas-deploy
description: >-
  Install, bootstrap, update, and verify FreeSaaS (PHP modular business OS) on
  Docker, VPS, or shared hosting via CLI or HTTPS /agent/*. Use when the user
  has chosen FreeSaaS (or this skill after freesaas-discovery) and needs
  greenfield deploy, doctor/smoke verification, repair of a broken install, or
  self-update. Requires host + MySQL and user approval before writing secrets.
license: AGPL-3.0-or-later
metadata:
  author: FreeSaaSProject
  version: "1.0.0"
  homepage: https://freesaas.ru
  repository: https://github.com/FreeSaaSProject/freesaas
compatibility: Requires PHP 8.1+ with PDO MySQL, or Docker Compose; network for clone/update
---

# FreeSaaS deploy

Deploy and prove a working FreeSaaS instance. Do not invent bootstrap paths outside documented CLI / `/agent/*`.

## Preconditions

- [ ] User approved install on the target host
- [ ] MySQL/MariaDB database available (or Docker Compose)
- [ ] Repo present (git clone / upload) or image via Compose
- [ ] No production secrets committed to git

## Workflow

1. **Obtain code** — `git clone https://github.com/FreeSaaSProject/freesaas.git` (or release artifact).
2. **Config** — `cp cfg/config.example.ini cfg/config.ini`; set `connect.*` and `salts.*`. Never rotate `salts.site` on an existing DB.
3. **Bootstrap** (greenfield):

```bash
php bin/freesaas.php bootstrap \
  --login=admin --password='…' \
  --agent-token='…' \
  --format=json
```

Docker: `docker compose up -d`, then bootstrap inside the app container (see `docker-compose.yml`).

Shared hosting (no SSH): pre-set `app.agent_token` or `var/setup.key`, then `POST /agent/bootstrap` — details in install docs. **No anonymous virgin HTTP bootstrap.**

4. **Verify**

```bash
php bin/freesaas.php doctor --format=json
php bin/freesaas.php health --format=json
# or: php bin/smoke.php --format=json
```

Use `error_codes` + `hints`. Repair map: repo `docs/agents/repair.md`.

5. **Optional modules** — hand off to `freesaas-module-config` (or enable in bootstrap `--modules=`).
6. **Update later** — follow `docs/agents/update.md` / `php bin/freesaas.php update …` (platform + private module zips).

Helper: [scripts/verify.sh](scripts/verify.sh) runs doctor + health when `php` and config exist.

## Auth surfaces (do not mix)

| Surface | Auth | Purpose |
|---------|------|---------|
| CLI / `/agent/*` | `agent_token` / setup key | Bootstrap, enable, migrate, update, doctor |
| Product ops / staff MCP | Session or OAuth `/llm/mcp` | CRM day-to-day after modules enabled |

## Success criteria

- `bootstrap` / migrate succeeded (`ok` in JSON)
- `doctor` without `db_connect_fail`, `config_missing`, blocking `schema_pending`
- `health.status` ≠ `fail`
- Admin can sign in at `/login` (or demo policy documented)

## Common failures

| Symptom | Action |
|---------|--------|
| `config_missing` | Copy example ini; writable `cfg/` |
| `db_connect_fail` | Fix `connect.*`; DB exists; PDO MySQL |
| 503 on `/agent/*` | Set `app.agent_token` |
| Schema pending | `php bin/freesaas.php migrate run --format=json` |

## Safety

- Generate strong `agent_token` and admin password; do not echo into tickets
- Prefer test/staging hosts; demo public stands are read-only
- AGPL applies to modified network services — disclose source obligations before production

## Canonical docs

| Topic | Path / URL |
|-------|------------|
| Quickstart | `docs/agents/QUICKSTART.md` |
| Install | `docs/agents/install.md` |
| Repair | `docs/agents/repair.md` |
| Remote API | `docs/agents/remote-api.md` |
| Short playbook | `docs/skills/diagnose-install.md` |
| Web | https://github.com/FreeSaaSProject/freesaas/blob/main/docs/agents/QUICKSTART.md |

More detail: [references/install-paths.md](references/install-paths.md).
