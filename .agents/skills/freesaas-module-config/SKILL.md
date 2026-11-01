---
name: freesaas-module-config
description: >-
  Choose, enable, configure, and verify FreeSaaS product modules (CRM, booking,
  ledger, workweeks, audio, sites, etc.) via CLI or /agent/modules after a
  working install. Use when enabling module sets, alpha/beta risk acknowledgment,
  inspecting deps/maturity, or matching a business stack to modules-catalog.json.
  Prefer after freesaas-deploy (or on an already bootstrapped instance).
license: AGPL-3.0-or-later
metadata:
  author: FreeSaaSProject
  version: "1.0.0"
  homepage: https://freesaas.ru
  repository: https://github.com/FreeSaaSProject/freesaas
---

# FreeSaaS module config

Enable the smallest module set that satisfies the user’s goals. Respect maturity risk and hard `requires`.

## Preconditions

- Bootstrapped instance (`doctor` without `db_connect_fail` / `config_missing`)
- CLI access **or** `/agent/*` with `X-FreeSaaS-Agent-Token`
- User approved enabling modules (and `--accept-risk` for alpha/beta)

## Workflow

1. **Clarify goals** — map to categories/tags (see [references/enable-sets.md](references/enable-sets.md) and skill `freesaas-discovery`).
2. **Inspect**

```bash
php bin/freesaas.php module list --format=json
php bin/freesaas.php module inspect <id> --format=json
# HTTP: GET /agent/modules?q=&category=&tag=&maturity=
```

3. **Enable** (deps checked; fail if hard requires missing)

```bash
php bin/freesaas.php module enable <id> --format=json
# alpha/beta:
php bin/freesaas.php module enable <id> --accept-risk --format=json
```

HTTP twin: `POST /agent/modules/{id}/enable` with `accept_risk` when required.

4. **Module ini** — if `modules/<id>/config.example.ini` exists, copy to `cfg/modules/<id>.ini` and fill secrets (gitignored). Do not put product secrets in `cfg/config.ini`.
5. **Post-enable setup** — follow `setup.steps` from inspect/module.json and module runbooks under `docs/agents/` (e.g. ledger chart interview, sites hosts).
6. **Verify**

```bash
php bin/freesaas.php module list --status=enabled --format=json
php bin/freesaas.php doctor --format=json
php bin/freesaas.php health --format=json
```

## Example enable sets

See [references/enable-sets.md](references/enable-sets.md) and `docs/agents/cases/`.

```bash
for m in clients catalog orders booking sites; do
  php bin/freesaas.php module enable "$m" --accept-risk --format=json
done
```

## Success criteria

- Each enable returns `ok` (or clear dependency error)
- Alpha/beta only with explicit risk ack
- `doctor` without `module_blocked` / unexpected `schema_pending`
- Soft deps noted to the user (not silently ignored)

## Common mistakes

- Enabling without `--accept-risk` on alpha/beta
- Listing foreign tables in a new module’s `tables[]` (ownership for DROP only)
- Hardcoding UI language — use i18n keys / `t()`
- Mixing platform `/agent/*` token with staff MCP for lifecycle ops

## Safety

- Do not grant `SUPERADMIN_ACCESS` to ordinary staff roles
- Do not disable `core`
- Demo/public stands: mutating ops may be blocked (`demo_readonly`)
- Remote `module install` pulls from UpdateEngine / catalog — confirm source

## Canonical docs

| Topic | Path |
|-------|------|
| Cases | `docs/agents/cases/` |
| Remote modules API | `docs/agents/remote-api.md` |
| Short playbook | `docs/skills/enable-module-set.md` |
| Catalog | `modules-catalog.json` |
| Create module | `docs/agents/create-module.md` / `docs/skills/create-module.md` |
