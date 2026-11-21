# FreeSaaS — system guide

Human-facing document: what the project is, how the code is structured, how to work with it, and how to add modules.

**Language:** English (canonical). Russian: [`GUIDE.ru.md`](GUIDE.ru.md).

For AI agents see [`AGENTS.md`](../AGENTS.md) at the repo root. Agent-native readiness audit and platform/module requirements: [`agent-native-audit.md`](agent-native-audit.md). Operation runbooks: [`agents/`](agents/).

---

## What it is

**FreeSaaS** is a modular PHP CRM / operator UI built from enableable product modules.

Hard rules:

- Runtime talks to **`base_*`** tables only.
- Features live in **modules** under `modules/<id>/` with a `module.json` manifest.

Table ownership: [`TABLE_MAP.md`](TABLE_MAP.md).

---

## Quick start

1. Copy `cfg/config.example.ini` → `cfg/config.ini` (local installs often already have one).
2. If needed, copy `modules/<id>/config.example.ini` → `cfg/modules/<id>.ini` (audio/calendar/ledger, etc.; secrets stay out of git).
3. Point the vhost docroot at this project root.
4. **Autonomous bootstrap** (migrations + first superadmin + `agent_token`):

```bash
# CLI
php bin/freesaas.php bootstrap --login=admin --password='…' --agent-token='…' --format=json

# Shared hosting (no SSH) — POST /agent/bootstrap, see agents/install.md
```

Or step by step: `php bin/freesaas.php migrate run` / UI `/db`, then create a user manually.

5. Sign in at `/login`. Further ops: CLI or `/agent/*` ([`agents/remote-api.md`](agents/remote-api.md)).

Enable/disable modules: UI `/modules` → **Activate**, CLI `module activate` / `module enable`, or `[modules]` in `cfg/config.ini` (`=1` explicitly; absent = off).

---

## Repository layout

```
freesaas/
├── index.php              # Front controller → Router::dispatch()
├── .htaccess              # Rewrite to index.php; block app/cfg/bin/docs
├── cfg/
│   ├── config.ini         # Platform: connect/app/salts/modules (not in git)
│   ├── config.example.ini
│   └── modules/           # Live module ini (*.ini gitignored); examples in modules/<id>/config.example.ini
├── app/                   # Platform core (not a module)
│   ├── bootstrap.php      # Load services + ModuleManager
│   ├── Config.php         # INI config, enable/disable modules
│   ├── Database.php       # PDO, tableExists / columnExists
│   ├── Auth.php           # Session, permissions, status capabilities
│   ├── I18n.php           # UI localization (t / catalogs / locale)
│   ├── Router.php         # Routes from ModuleManager
│   ├── View.php           # Render views + layout
│   ├── EventBus.php       # Cross-module hooks
│   ├── ModuleManager.php  # Discovery, deps, menu, registry
│   ├── SchemaMigrator.php # DDL migrations modules/*/migrations
│   ├── DataMigrator.php   # Optional one-shot data migrators from manifests
│   ├── StageTiming.php    # Request stage timings
│   ├── ListQuery.php      # List pagination and column sort
│   ├── BulkActions.php    # Row selection and group operations
│   ├── helpers.php        # e(), t(), redirect(), safe_html(), …
│   └── lang/              # Shared UI strings (ru/en)
├── bin/
│   ├── freesaas.php       # Agent/ops CLI (system, module, migrate, update, locale, health)
│   ├── build-release-artifacts.sh  # Release zips + manifest for UpdateEngine
│   ├── smoke.php          # Minimal CI smoke
│   ├── schema-migrate.php
│   ├── data-migrate.php
│   ├── check-i18n.php
│   ├── health.php
│   └── module-validate.php
├── modules/
│   ├── core/              # Platform: auth, users, news, settings…
│   ├── comments/          # Comments on news/tickets (no own URLs)
│   ├── contracts/         # Contracts / offers
│   └── clients/           # Client base + client statuses
├── assets/css|js          # Core chrome (core.css / core.js)
├── modules/<id>/assets/   # Module CSS/JS (page-scoped via Assets)
├── docs/                  # Documentation
└── log/                   # Logs (if used)
```

---

## Request flow

```
Browser → .htaccess → index.php
  → app/bootstrap.php
       Config::load
       Auth::startSession
       I18n::bootFromRequest()
       ModuleManager::discover()      # reads modules/*/module.json
       ModuleManager::bootEnabled()   # require bootstrap.php of enabled modules
       syncRegistry() into base_modules  # if table exists
  → Router::dispatch()
       path + method → route
       auth / permission
       handler() → pages/*.php → View::render()
```

Important details:

- Path `/modules` is forced into `index.php` (otherwise Apache serves the `modules/` directory and returns 403).
- Direct access to `app/`, `cfg/`, `bin/`, `docs/` is forbidden.
- Routes with `{id}` match via regex; when several match, the longer pattern wins.

---

## Core (`app/`)

| Class | Role |
|-------|------|
| **Config** | Main `cfg/config.ini` (platform) + merge `cfg/modules/*.ini`. `moduleEnabled` / `setModuleEnabled`. Product `Config::set('calendar',…)` writes `cfg/modules/calendar.ini`. `core` is always on. Module without a key in `[modules]` = **off**. |
| **Database** | Single PDO (utf8mb4). Cache for `tableExists` / `columnExists`. |
| **Auth** | Session `freesaas`. Permissions from `base_users.status` (unserialized) + role flags from `base_admin_statuses` (`capability`). Superadmin bypasses checks. |
| **ModuleManager** | Discovery, semver deps, boot, routes, menu, sync to `base_modules`, module UI status. |
| **Router** | Dispatcher. `public`, `permission`, `methods`, `handler`. |
| **View** | `modules/<module>/views/<name>.php` + layout from `modules/core/views/`. |
| **ListQuery** | Shared pager (`page`) and column sort (`sort`/`dir`) for list/CRUD. SQL allow-list, HTML pager + `sortTh`. |
| **BulkActions** | Row checkboxes and group ops. Register actions in module `bootstrap`; UI helpers; dependency checks via `core.bulk.check_item`. |
| **I18n** | UI locales `ru`/`en`: catalogs `app/lang/` + `modules/*/lang/`, `t()`/`te()`/`tj()`, cookie/session/`base_users.locale`, switcher `/locale`. |
| **EventBus** | `on` / `emit`: listener chain mutates payload and returns it. |
| **SchemaMigrator** | Files `migrations/*.php` by name order; ledger `base_schema_migrations`. |
| **DataMigrator** | Files `migrators/*.php` listed in `module.json` (one-shot replace/seed; not normal runtime). |

Helpers (`helpers.php`): escaping (`e`, `e_nl`, `safe_html`, `comment_html`), UI translation (`t`, `te`, `tj`), `redirect`, `request_path`, `base_path`, feed UI helpers (`deadline_chip`, `status_pill`, `status_comment_parts`).

### UI localization

- Interface strings go through `t('module.key')` (HTML attributes/text — `te()`, `confirm()` — `tj()`).
- Catalogs: `app/lang/{locale}.php` and `modules/<id>/lang/{locale}.php` (flat key → string arrays).
- Language: session → cookie `freesaas.locale` → `base_users.locale` (on login) → `app.locale` in ini (default `ru`).
- Switcher in topbar / login → `GET /locale?lang=en&return=…`.
- User-generated content (news, comments, clients) and status `NAME` values from the DB are **not** translated.
- New locale: add `app/lang/{code}.php` and `modules/*/lang/{code}.php`; supported list is discovered from disk (optional `app.locales` in ini). Check: `php bin/freesaas.php locale validate --locale={code}`.

---

## Modules

### Module anatomy

```
modules/<id>/
├── module.json       # manifest (required)
├── bootstrap.php     # routes, EventBus::on, require lib/
├── lang/             # ru.php + en.php — UI chrome (required)
├── pages/            # HTTP handlers (one file per screen/action)
├── views/            # templates for View::render(..., module: <id>)
├── lib/              # *Repository.php and other logic
├── migrations/       # schema: return function(PDO $pdo): void
└── migrators/        # data: return function(PDO $pdo, array $spec)
```

Not every module has `pages/` (e.g. `comments` only extends other screens via hooks). `lang/ru.php` and `lang/en.php` are required whenever the module has UI strings (menu, permissions, widgets, flash).

### `module.json` fields

| Field | Meaning |
|-------|---------|
| `id`, `name`, `version` | Identity; version for deps (`>=0.3.0`, `^1.0`, `~1.2`) |
| `type` | `platform` (core) or `product` |
| `description` | i18n key `{id}.module.description` (short blurb ru+en); not English prose in JSON |
| `catalog` | Catalog UI/agents: `{ categories[], maturity, tags[], screenshots[] }` — many-to-many categories (allowlist `ModuleCatalog`); `maturity`: `alpha`\|`beta`\|`release` (often `beta` today); screenshots under `assets/catalog/` |
| `requires` / `optionalRequires` | Dependencies on other modules |
| `provides` | Capability declaration (catalog / agents; runtime does not consume yet) |
| `permissions` | `{ id, label }` — `label` = i18n key (`myid.perm.view`), not ready-made text |
| `routes.pages` | Path list (actual registration is in `bootstrap.php`) |
| `menu` | Rail items: `group`, `num`, `path`, `title` — `group`/`title` are i18n keys too |
| `tables` | Exclusively owned `base_*` (uninstall dump→DROP). Do not list foreign tables the module only uses/`ALTER`s |
| `migrators` | Data-migrator specs |
| `hooks.subscribe` / `hooks.publish` | EventBus event documentation |

### Enable and dependencies

- Enable via `/modules` or `cfg/config.ini` → `[modules] id=1|0`.
- On **enable**, the module’s schema migrations run first, then the flag is written.
- You cannot disable a module that other enabled modules depend on.
- `core` cannot be disabled.
- If a hard dep fails, the module does not boot (`_block_reason` in log/UI).

### Current modules (sample)

| Module | Role |
|--------|------|
| **core** | Login, home, users, statuses, news/feed/tickets, settings, modules UI, migrate |
| **comments** | Comment tree on news cards; likes; prepare_add / form hooks |
| **contracts** | Contract list, offers in comment form, comment actions, widgets/notices |
| **clients** | Client base (`base_clients`) and client statuses (`base_client_statuses`) |
| **rates** / **procents** / **payments** | Payroll: rates, accruals, settlements |
| **vacations** | Leave register (Team menu, next to shifts) |
| **ledger** | Accounts, move types, DPS, transfers, reconciliations |
| **funds** | Funds and fund movements (CAPUSE/REFUND); requires ledger |
| **finance** | Financial reporting (cash-flow / P&L / balance without inflation or amortization) |
| **liquid_assets** | Liquid assets + T-Invest (portfolio snapshots); requires ledger; soft funds; disableable |

Full inventory: [`AGENTS.md`](../AGENTS.md) «Existing modules» and [`TABLE_MAP.md`](TABLE_MAP.md).

---

## Pattern: page → view → repository

Typical screen (contracts example):

1. **`bootstrap.php`** registers the route → `require pages/list.php`.
2. **`pages/list.php`** reads GET/POST, calls `*Repository`, then `View::render('list', $data, 'layout', 'contracts')`.
3. **`views/list.php`** — HTML (variables from `extract`).
4. **`lib/ContractsRepository.php`** — SQL against `base_*` only.

Breadcrumbs (system chrome in layout):

1. In the **page**, build the full trail from the module inward and wrap with `Breadcrumbs::withHome(...)` — Home (`/`) is always first. List root: `['label' => t('myid.menu.list'), 'current' => true]`.
2. Pass to `View::render(..., ['breadcrumbs' => $trail, 'breadcrumbs_aria' => t('…')])`. Layout draws them **at the top** of `<main>` on every page.
3. Do **not** call `Breadcrumbs::render` in the view — that would duplicate. If `breadcrumbs` is omitted, layout builds `Home / menu item / title` itself.
4. **Auto-link list root:** on an exact menu path, if the query has filters/pager/sort, the leaf crumb becomes a link to the clean list URL (modules need no opt-in). Prefer keeping the default screen on a URL without query.
5. Partial: `modules/core/views/_breadcrumbs.php`.
6. **Return with filters (`NavContext`) — automatic**; modules do not wire this:
   - `View::render` runs HTML through `NavContext::enrichContent`: outbound links to another path get `ret=` (current URL with filters); bare cancel/up links matching the `ret` path become the full `ret`; forms get a hidden `ret`.
   - `redirect()` calls `NavContext::redirectUrl` (same path as `ret` → return to filters; otherwise `ret` is preserved).
   - Crumbs / `[Return]` in layout: `up()` and the return button.
   - `core.js` mirrors the logic for dynamic links and submit.
   - Opt-out: `data-nav-no-ret` on `<a>` / `<form>`. Explicit helpers (`withReturn`, `hiddenField`, …) remain for rare edge cases (e.g. `window.location =` in custom JS).

Permissions:

- Route `permission` → `Auth::can('module.perm')`.
- Role business flags → `Auth::capability('CONTROL_ACCESS')` etc. from `base_admin_statuses`.

URL params: `Router::param('id')` for `{id}` in the pattern.

---

## EventBus (extend without editing another module)

Pattern: one module **emit**s, others **on** and return the mutated payload.

Example events:

| Event | Who emits | Why |
|-------|-----------|-----|
| `core.dashboard.catalog` | home / dashboard | Desktop widget catalog |
| `core.dashboard.widgets` | home | Home widgets |
| `core.notices.collect` | layout | Bell / notices |
| `core.layout.gate` | layout | Chrome intercept: `blocked` + `html`, slots `chrome` (rail) and `floating` (FAB), `page_context_json`, extra `js_i18n` (no product code in core) |
| `core.news_item.handle_post` | news_item | POST actions (like, comment…) |
| `core.news_item.section` | news_item | HTML section under the card |
| `core.news_item.meta` | news_item | Meta/counters in the header |
| `comments.prepare_add` | comments | Enrich text/extra before insert |
| `comments.form.offer_fields` | comments form | Offer fields (contracts) |
| `comments.form.ticket_controls` | comments form | Status/curator/OL (core) |
| `comments.render_actions` | comments view | Buttons on a comment (contracts) |
| `core.auth.after_login` | Auth | After successful login |
| `core.auth.before_logout` | Auth | Before session clear (`user_id`) |
| `core.bulk.check_item` | BulkActions | Before each id in a group op: `blocked` / `reason` |
| `core.settings.sections` | settings | Module HTML blocks on `/settings` (platform; product settings live on `/<module>/settings`) |
| `core.settings.save` | settings | Persist fields modules added on `/settings` |
| `core.menu.items` | layout | Mutate rail items after build (`items[]`); e.g. assistant persona name |
| `audio.settings.sections` | audio settings | HTML blocks on `/audio/settings` (e.g. `audio_reports`) |
| `audio.settings.save` | audio settings | Persist sections on `/audio/settings` |

Listeners register in the module’s `bootstrap.php`. Order = module boot order (sorted by id).

---

## Bulk operations (BulkActions)

Platform service [`app/BulkActions.php`](../app/BulkActions.php) — checkboxes in list tables and actions over selected ids.

### Registration (in module `bootstrap.php`)

```php
BulkActions::register('units', 'delete', [
    'label' => 'core.bulk.delete',          // i18n key
    'permission' => 'units.delete',         // dedicated delete permission (not .edit)
    'confirm' => 'core.bulk.confirm_delete',
    'fields' => [ /* optional: select/input when the action is chosen */ ],
    'run' => static function (array $ids, array $params): array {
        return ['done' => UnitsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
```

`list_id` matches `ListQuery` (`units`, `procedures`, `procedure_types`, …). The action is visible only when `permission` / `capability` passes.

### Action row on screen

Canon: **`page-actions`** (Save / Cancel / other CTAs in one flex row).

### Delete on edit screens (`EntityDelete`)

[`app/EntityDelete.php`](../app/EntityDelete.php) — delete button on edit, same `list_id`/`delete` runner and `core.bulk.check_item` as the list. Place markup **immediately after** the edit `</form>` (separate POST, no nested form). `assets/js/core.js` moves the button into the form’s last `.page-actions` (right side); if delete is blocked (`core.bulk.check_item`), the button is `disabled` and the reason appears as a paragraph under the row.

```php
// page (before save):
if (!$isNew && EntityDelete::tryHandle('units', $id, '/units', [
    'stay_path' => '/units/' . $id . '/edit',
])) {
    return;
}
// view:
<form method="post" class="form-grid">
  …
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/units"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$isNew): ?>
<?= EntityDelete::form('units', $id) ?>
<?php endif; ?>
```

JSON/agent delete is additionally gated by `app.agent_allow_delete` (UI: **/settings → Agent**; default off). Staff UI with `*.delete` permission does not depend on that flag.

### UI in list view

```php
$actionUrl = ListQuery::url($path, $filters, []);
<?= BulkActions::beginForm('units', $actionUrl) ?>
<?= BulkActions::bar('units') ?>
<table class="data">
  <thead><tr><?= BulkActions::th('units') ?>…</tr></thead>
  <tbody>
    <tr><?= BulkActions::td('units', (int)$row['id']) ?>…</tr>
  </tbody>
</table>
<?= BulkActions::endForm('units') ?>
```

In the page: `BulkActions::tryHandle('units', '/units', $filters)` on POST, then `$bulkFlash = BulkActions::consumeFlash()` and `BulkActions::flashHtml($bulkFlash)` in the view. The list route must accept `POST`.

### Dependency checks

Before each id, `BulkActions` emits `core.bulk.check_item` (`list_id`, `action`, `id`, `params`, `blocked`, `reason`). Another module sets `blocked=true` and a reason — the id goes to skipped and delete is not called. Example: catalog blocks delete of units/suppliers/procedure types when products exist; procedures block client delete when procedures exist.

Shared UI strings: `core.bulk.*` in `app/lang/`. Select-all covers the current page only. Clicking the checkbox cell (not only the input) toggles the row; Shift+click selects or clears a range from the previous checkbox; selected rows are highlighted (`is-bulk-selected`).

---

## Migrations

### Schema (`migrations/NNN_name.php`)

```php
<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS base_example (...)');
};
```

- Filename = migration id; lexicographic sort order.
- Applied once; recorded in `base_schema_migrations`.
- CLI: `php bin/freesaas.php migrate run` or UI `/db` / on module connect.

### Data (`migrators/<id>.php`)

Optional one-shot imports/replacements declared in `module.json` → `migrators`. Normal runtime (pages, lib, views, EventBus) uses `base_*` only.

---

## Auth and permissions

1. **Route**: `Auth::can('module.perm')` — from the personal blob; superadmin always passes.
2. **Menu**: `Auth::canSeeInMenu('module.perm')` — the same profile checkboxes, **including superadmin** (unchecked hides the rail item; the URL may still open).
3. **Role**: flag columns in `base_admin_statuses` → `Auth::capability('FLAG')`.
4. Layout footer: if namespaced permissions were never saved — prompt to configure the profile; if superadmin opened a screen with a personal checkbox off — remind about bypass.

Password: new writes use `password_hash` (argon2id/bcrypt); legacy `sha1(md5(plain . salts.site))` still verifies and rehashes on successful login. Do not rotate `salts.site`. Lockout: `retries` + `locked_until` (5/15 min). Bot-free login link: `php bin/freesaas.php user login-link <login>` or the button on the staff card.

Sessions: files under `var/sessions/<host>/` (not `/tmp`). `Auth::check()` revalidates `enabled` / `auth_epoch` / permissions (throttled ~60s; always on POST and capability routes). Password/role/enabled changes bump `auth_epoch` (other sessions die). Idle: `session_idle_minutes` (default 120) only without «Remember me»; with remember, `session_remember_days` applies (sliding cookie, including after `session_regenerate_id`). Do not share one `session_cookie_domain=.example.com` across two installs — cookie name would collide and logins would kick each other; use distinct `session_name`. CSRF token is one per session (rotated on login); periodic `session_regenerate_id` does not change CSRF — multiple tabs with one form stay fine. «Log out everywhere»: UI on the staff card or `php bin/freesaas.php user logout-all <login>`.

---

## UI / front end

- Layout: `modules/core/views/layout.php` (and `layout_auth.php` for login).
- Core: `assets/css/core.css`, `assets/js/core.js` (always in layout).
- Modules: `modules/<id>/assets/{css,js}/` — register in `bootstrap.php` via `Assets::register('myid', ['css' => ['x.css'], 'js' => ['y.js']])`. `View::render(..., 'myid')` queues the module; embeds of another UI — `Assets::need('other')`. Layout emits tags via `Assets::cssTags()` / `Assets::jsTags()`.
- Module static files are served directly (`.htaccess` exception for `/modules/*/assets/*.{css,js,…}`); the rest of `/modules` goes to the front controller.
- Menu: items from `module.json` → `ModuleManager::menuItems()` / `menuGrouped()`. Rail **group** order — `ModuleManager::registerMenuGroup('myid.menu.group', N)` in the owning module’s bootstrap (not in `app/ModuleManager.php`). Shared `core.menu.group.*` — only `modules/core/bootstrap.php`. Unknown key → weight `1000`.
- Staff edit: core emits `core.user_edit.sections` / `save` / `profile`; product fields only from module bootstraps.
- Staff list: `core.users.list` — `items` + `extra_columns` (`[{key,label}]`); modules enrich rows (e.g. `bots` → chat ids from `base_bot_links`).
- Clients: `clients.edit.sections` / `save` / `clients.view.profile` / `clients.list` — same pattern, no messenger columns in `base_clients`.
- Role flags (`base_admin_statuses`): `StatusesRepository::registerFieldGroup` in bootstrap; core keeps only `when=always` groups.
- Module config: `modules/<id>/config.example.ini` → `cfg/modules/<id>.ini`; do not add product sections to `config.example.ini`.

---

## How to add a new module

Short checklist:

1. Create `modules/myfeature/`.
2. Write `module.json` (`id`, `version`, `requires: { "core": ">=0.3.0" }`, permissions, menu, tables, hooks). Fields `name`, `menu[].title|group`, `permissions[].label` are **translation keys**, not single-language strings.
3. Add `lang/ru.php` and `lang/en.php` with all module UI strings; in views/pages use only `t()` / `te()` / `tj()`.
4. Add `bootstrap.php`: `require` repositories, optionally `Assets::register` / `registerMenuGroup` / `StatusesRepository::registerFieldGroup` / `core.user_edit.*`, `ModuleManager::registerRoute(...)`, `EventBus::on(...)`.
5. As needed: `pages/`, `views/`, `lib/`, `migrations/`, `assets/{css,js}/`, `config.example.ini`.
6. Activate via UI `/modules/{id}/activate` or `module activate` (migrations on enable). Copy config.example → `cfg/modules/` when present. Fill `description` + `catalog` + `{id}.module.summary` + `setup.steps` (ru/en).
7. Runtime and new tables — only `base_*`; table ownership in `module.json` → `tables`.
8. To plug into an existing screen — subscribe to a hook; do not edit another module’s page unless necessary.

Minimal `bootstrap.php` with one screen:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/MyRepository.php';

ModuleManager::registerMenuGroup('myfeature.menu.group', 45);

ModuleManager::registerRoute('/myfeature', [
    'module' => 'myfeature',
    'methods' => ['GET'],
    'permission' => 'myfeature.view',
    'handler' => static function (): void {
        require base_path('modules/myfeature/pages/index.php');
    },
]);
```

In the page:

```php
View::render('index', ['title' => 'My feature', /* ... */], 'layout', 'myfeature');
```

---

## CLI

Canonical protocol for agents and ops — [`bin/freesaas.php`](../bin/freesaas.php) (text or `--format=json`). Exit codes: `0` ok, `1` fail, `2` usage.

| Command | Action |
|---------|--------|
| `php bin/freesaas.php system status` | PHP/DB/config, locale, module counts |
| `php bin/freesaas.php bootstrap --login=… --password=…` | First admin + migrate + `agent_token` (see [`agents/install.md`](agents/install.md)) |
| `php bin/freesaas.php module list [--q=] [--category=] [--tag=] [--status=] [--maturity=]` | Module catalog (bilocale name/description, filters); `available[]` — not yet on disk |
| `php bin/freesaas.php module install <id>` | Download module from the update repo (not on disk); then `module activate` |
| `php bin/freesaas.php module activate <id> …` | Snapshot / apply: enable, grant, setup.steps (UI twin `/modules/{id}/activate`) |
| `php bin/freesaas.php module pin-effective` | Upgrade: absent → `=1`, then `app.modules_absent_off=1` (new absents stay off) |
| `php bin/freesaas.php module recover-from-example` | Optionally enable ids with `=1` in `config.example.ini` if currently off |
| `php bin/freesaas.php module uninstall <id> --confirm` | Backup owned tables → DROP → ledger clear → disable (`leave_tables` refused) |
| `php bin/freesaas.php module enable <id> [--accept-risk]` | Enable; alpha/beta needs `--accept-risk` |
| `php bin/freesaas.php module inspect <id>` | Manifest + routes + `agent_ops` / hooks / bulk / soft_deps / capabilities |
| `php bin/freesaas.php module enable\|disable <id>` | On/off (enable runs schema migrate) |
| `php bin/freesaas.php module validate [id]` | Structure + soft-dep honesty (`isEnabled` / foreign pages) |
| `php bin/freesaas.php migrate status\|run [--module=id]` | Schema migration status and run |
| `php bin/freesaas.php locale list\|validate [--locale=xx]` | Supported locales (`app/lang` discovery) and catalog parity |
| `php bin/freesaas.php health [--module=id]` | Liveness/readiness/health checks |
| `php bin/freesaas.php i18n check` | Same as locale validate + used-key scan |
| `php bin/freesaas.php update status\|check\|apply [--target=…]` | Core/module self-update ([`agents/update.md`](agents/update.md)) |
| `php bin/smoke.php` | Fast CI: system + locale + validate + health |
| `php bin/schema-migrate.php` | Compat: DDL for all enabled |
| `php bin/data-migrate.php` | Data migrators from manifests |

Thin wrappers: `bin/health.php`, `bin/module-validate.php`, `bin/check-i18n.php`.

HTTP (same `Health::run` / `AgentOps`):

- `GET /health` — public minimal JSON (`status`, `liveness`, `readiness`, `summary`)
- `GET /health/detail` — full check list (needs `core.modules`); `?module=id`
- **`/agent/*`** — full ops API for agents on shared hosting (token `app.agent_token`, header `X-FreeSaaS-Agent-Token`; always required). First HTTP bring-up: pre-set token **or** `var/setup.key`, then `POST /agent/bootstrap` — no anonymous virgin bootstrap ([`agents/install.md`](agents/install.md)). Catalog: `GET /agent`. Self-update: [`agents/update.md`](agents/update.md). See [`agents/remote-api.md`](agents/remote-api.md).

Health: platform checks (DB, ledger, config, blocked modules, agent_token) + auto-check of `tables[]` from `module.json` + module `Health::register` (e.g. `asr.worker_token`). Modules register checks in `bootstrap.php`.

Locales: list from `app/lang/*.php`; optional `app.locales` in ini. Uninstall still = disable (tables remain) unless `module uninstall --confirm` — see [`agent-native-audit.md`](agent-native-audit.md). Agent runbooks: [`agents/`](agents/).

---

## Where to look next in the code

- Core route registration: `modules/core/bootstrap.php`
- News card extension: `modules/comments/bootstrap.php` + `modules/core/pages/news_item.php`
- Contracts as a product-module example: `modules/contracts/`
- Module catalog: `modules/core/pages/modules.php` + detail `/modules/{id}`; DTO `app/ModuleCatalog.php`
