# Create a module

Operational runbook for coding agents. Architecture rules: [`../AGENTS.md`](../../AGENTS.md), standard: [`../agent-native-audit.md`](../agent-native-audit.md).

## Goal

Add a new product module under `modules/<id>/` that passes acceptance.

## Steps

1. **Pick id** — lowercase snake, ASCII (`equipment`, not `оборуд`).
2. **Clone template** — copy structure from [`modules/units/`](../../modules/units/) for CRUD dictionaries.
3. **Create files**
   - `module.json` — `id`, `name` (i18n key), `description` (i18n key `{id}.module.description`), `catalog` (`categories[]` many-to-many + `maturity`=`alpha|beta|release` + `tags` from `ModuleCatalog` allowlists; optional `screenshots` under `assets/catalog/`), `version`, `type: product`, `requires: { "core": ">=0.3.0" }`, `optionalRequires`, `permissions`, `menu`, `tables`, `hooks` (dotted EventBus names only), `routes.pages`, optional `bulkActions`, optional `agent.ops` ([module-agent-api.md](module-agent-api.md)). Until hardened, prefer `maturity: beta` (enable requires risk ack for alpha/beta).
   - After adding a new product module to the monorepo, regenerate the remote install index: `php bin/build-modules-catalog.php` (commit `modules-catalog.json`). Instances with UpdateEngine configured will show it under **Modules → Available to install**.
   - `lang/ru.php` + `lang/en.php` — include `{id}.module.description` and `{id}.module.summary` (when to enable, hard/soft deps in plain language for humans and agents)
   - `bootstrap.php` — `require_once` repos, `registerRoute`, optional `BulkActions`, `EventBus::on`, `Health::register`, `Assets::register`
   - `migrations/001_*.php` — `return function (PDO $pdo): void`
   - `lib/*Repository.php`, `pages/`, `views/`
4. **Enable**

```bash
php bin/freesaas.php module enable <id> --format=json
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

Disable only (`module disable`). Tables remain until a future `uninstall.strategy=drop_owned` implementation.
