# Create a module

Operational runbook for coding agents. Architecture rules: [`../AGENTS.md`](../../AGENTS.md), standard: [`../agent-native-audit.md`](../agent-native-audit.md).

## Goal

Add a new product module under `modules/<id>/` that passes acceptance.

## Steps

1. **Pick id** — lowercase snake, ASCII (`equipment`, not `оборуд`).
2. **Scaffold (preferred)**

```bash
php bin/freesaas.php module create equipment --title='Equipment' --format=json
php bin/freesaas.php module add-migration equipment add_serial --format=json   # optional stub
```

Or clone [`modules/units/`](../../modules/units/) by hand for a richer CRUD dictionary.

3. **Edit generated files**
   - `module.json` — adjust `catalog`, `requires`, `permissions`, `menu`, `tables`, `hooks` (dotted EventBus names only), `agent.ops` ([module-agent-api.md](module-agent-api.md)), and **`setup.steps`** (first-run checklist; may be `[]` for pure dictionaries). Until hardened, prefer `maturity: beta` (activate requires risk ack for alpha/beta).
   - Scaffold pins `[modules] <id>=0` (absent keys are disabled).
   - After adding a new product module to the monorepo, regenerate the remote install index: `php bin/build-modules-catalog.php` (commit `modules-catalog.json`). Instances with UpdateEngine configured will show it under **Modules → Available to install**.
   - `lang/ru.php` + `lang/en.php` — keep `{id}.module.description`, `{id}.module.summary`, and `{id}.setup.*` keys used by `setup.steps`
   - `bootstrap.php` / `lib/*Repository.php` / `pages/` / `views/` / migrations
4. **Activate** (UI `/modules/{id}/activate` or agent snapshot/apply — not a blind enable)

```bash
php bin/freesaas.php module activate <id> --format=json
php bin/freesaas.php module activate <id> --action=enable --accept-risk --format=json
# then grant / setup_step with recommended choices from the snapshot
```

5. **Accept**

```bash
php bin/freesaas.php module validate <id>
php bin/freesaas.php locale validate
php bin/freesaas.php health --module=<id>
php bin/smoke.php
```

Exit codes must be `0` (warnings OK on validate if no errors).

## Do not

- Invent a second table prefix or query non-`base_*` product tables from runtime
- Invent a second router
- Put prose in `hooks.publish` / `hooks.subscribe`
- Hardcode UI strings — use `t()` / `te()` / `tj()`
- `require` another module’s `pages/` — use EventBus

## Uninstall policy

- **Disable** (`module disable`): config off; tables and data remain.
- **Uninstall** (`module uninstall <id> --confirm` / `POST /agent/modules/{id}/uninstall` with `confirm_uninstall=1`): mandatory SQL dump of **exclusively owned** `tables[]` → DROP those → clear migration ledger → disable. Code under `modules/<id>/` stays. Omit `uninstall` or set `"uninstall":{"strategy":"drop_owned"}` to allow; `"leave_tables"` refuses DROP (disable-only).
- **`tables[]` = ownership, not usage.** Only list `base_*` this module creates and alone owns. If you only `ALTER`/query tables of `core`/`comments`/…, leave `tables` empty (or omit those names) and note the columns in [`docs/TABLE_MAP.md`](../TABLE_MAP.md). Duplicate ownership across modules fails `module validate`. Uninstall never DROPs a table also claimed by another discovered module.
