# AGENTS.md — FreeSaaS (likespa)

Instructions for coding agents working in this repository. Human guide: [`docs/GUIDE.md`](docs/GUIDE.md). Legacy table map: [`docs/LEGACY_TABLE_MAP.md`](docs/LEGACY_TABLE_MAP.md).

## Stack

- PHP 7.4+/8.x style with `declare(strict_types=1);`, no Composer framework.
- Front controller: `index.php` → `app/bootstrap.php` → `Router::dispatch()`.
- MySQL via PDO (`app/Database.php`). Config: `cfg/config.ini`.
- Modules under `modules/<id>/` with `module.json` + `bootstrap.php`.

## Hard rules (do not violate)

1. **Never query `phpshop_*` in runtime code** (pages, lib, views, EventBus handlers). Only data migrators under `modules/*/migrators/` may read legacy tables to copy into `base_*`.
2. **New tables / columns use `base_*`.** Prefer additive migrations (`CREATE IF NOT EXISTS`, `ALTER` with existence checks).
3. **Do not invent a second routing/DI layer.** Register routes in module `bootstrap.php` via `ModuleManager::registerRoute`. Use `View::render`, `EventBus`, existing repositories.
4. **Cross-module UI/behavior goes through EventBus**, not by hard-coupling pages of another module when a hook already exists (see `hooks` in each `module.json`).
5. **UI chrome via i18n (mandatory for every module):** use `t('module.key')` / `te()` / `tj()`; catalogs in `modules/<id>/lang/{ru,en}.php` (and `app/lang/` for shared). New modules ship both locales from day one. Do not hardcode UI language in views/pages/`module.json` labels. English for code identifiers; short focused diffs; no drive-by refactors or unsolicited markdown docs. UGC and DB status `NAME` values are not translated.
6. **Do not commit secrets** (`cfg/config.ini` with real passwords). Prefer editing `config.example.ini` only when documenting new keys.
7. **Do not disable or remove `core`.** Module enable/disable is via `Config::setModuleEnabled` / UI `/modules` / `[modules]` in ini.

## Request flow (mental model)

```
index.php → Config + Auth session → ModuleManager::discover + bootEnabled
→ Router: match path/{params}, methods, public|Auth::check, Auth::can(permission)
→ handler (usually require modules/<id>/pages/*.php) → View::render
```

Route params: `Router::param('id')`. Redirect: `redirect('/path')`. Escape output: `e()`, `e_nl()`, `safe_html()`, `comment_html()`. UI strings: `t()` / `te()` / `tj()` (see I18n).

## Where to put code

| Need | Place |
|------|--------|
| Shared platform service | `app/*.php` (only if truly cross-module; prefer module lib otherwise). Examples: `ListQuery` (pager + column sort), `I18n`. |
| UI translation strings | `modules/<id>/lang/{ru,en}.php` or `app/lang/` for shared chrome; keys like `core.layout.logout` |
| New product feature | New or existing `modules/<id>/` |
| HTTP endpoint | `ModuleManager::registerRoute` in that module’s `bootstrap.php` + `pages/` |
| HTML for a page | `modules/<id>/views/` + `View::render($name, $data, 'layout', $moduleId)` |
| SQL / domain logic | `modules/<id>/lib/*Repository.php` |
| Schema change | `modules/<id>/migrations/NNN_description.php` returning `function (PDO $pdo): void` |
| One-time legacy copy | `modules/<id>/migrators/<id>.php` + entry in `module.json` `migrators` |
| Extend home widgets / news card / comment form | `EventBus::on(...)` in `bootstrap.php` |
| List row selection / group ops | `BulkActions::register($listId, $action, …)` in bootstrap; view helpers `th`/`td`/`bar`; dependency blocks via `EventBus::on('core.bulk.check_item')` |
| Breadcrumb trail UI | Page passes `breadcrumbs` (+ optional `breadcrumbs_aria`) to `View::render`; layout always draws them at top of `<main>` via `Breadcrumbs` (`modules/core/lib/Breadcrumbs.php`). If omitted, auto from menu + page title. |
| Module CSS/JS | `modules/<id>/assets/{css,js}/` + `Assets::register($id, …)` in bootstrap. `View::render(..., $id)` auto-queues; embeds call `Assets::need('other')`. Core chrome: `assets/css/core.css`, `assets/js/core.js`. |

Layouts always live in `modules/core/views/` (`layout`, `layout_auth`, `error`).

## Module checklist (new module)

Create `modules/<id>/` with:

1. `module.json` — required keys: `id`, `name`, `version`, `type` (`product`), `requires` (at least `"core": ">=0.3.0"`), `permissions`, `menu` (if nav), `tables`, `hooks` (document emit/subscribe), `routes.pages` (list paths for humans; real routes are in bootstrap).
2. **i18n (required):** `lang/ru.php` + `lang/en.php` with all UI chrome strings. `module.json` `name`, `menu[].title|group`, and `permissions[].label` must be translation **keys** (e.g. `myid.menu.list`), not hardcoded language text. Views/pages/bootstrap/lib labels use `t()` / `te()` / `tj()` — never hardcode Russian or English UI copy.
3. `bootstrap.php` — `require_once` libs; `Assets::register` if the module has CSS/JS; register routes; subscribe EventBus.
4. Optional: `pages/`, `views/`, `lib/`, `migrations/`, `assets/{css,js}/`.
5. Enable: `[modules] <id>=1` or Modules UI (runs schema migrations on enable).

Do **not** translate UGC or DB reference `NAME` fields; only chrome (buttons, flashes, menu, column headers, empty states, JS prompts).

Route registration shape:

```php
ModuleManager::registerRoute('/path/{id}', [
    'module' => 'myid',
    'methods' => ['GET', 'POST'],
    'permission' => 'myid.view', // or 'public' => true
    'handler' => static function (): void {
        require base_path('modules/myid/pages/foo.php');
    },
]);
```

Page ends with:

```php
View::render('foo', [/* vars */], 'layout', 'myid');
```

### Breadcrumbs

Oper-style trail rendered by the **layout** at the top of every authenticated page. Pages build items and pass them to `View::render` (or call `Breadcrumbs::set()`). Always start from system Home via `withHome()`:

```php
View::render('foo', [
    'title' => $title,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('myid.menu.list'), 'href' => '/myid'],
        ['label' => $folderName, 'current' => true],
    ]),
    'breadcrumbs_aria' => t('myid.folder_aria'), // optional
], 'layout', 'myid');
```

Do **not** call `Breadcrumbs::render` inside module views — chrome owns placement. If `breadcrumbs` is omitted, layout auto-builds `Home / {best menu match} / {page title}` so every screen still has a path.

Item keys: `label` (required), `href` (optional), `current` (optional). Ancestors stay links; the deepest level is `current` (plain text).

## Auth

- Session name `freesaas`. Keys: `user_id`, `login`, `user_name`, `permissions`, `status_id`, `status_flags`.
- Route gate: `Auth::can('module.perm')` (superadmin always true).
- Menu visibility: `Auth::canSeeInMenu('module.perm')` — personal checkboxes even for superadmin (hide unused modules from the rail).
- Role flags from `base_admin_statuses`: `Auth::capability('FLAG_NAME')` (e.g. `CONTROL_ACCESS`, `SUPERADMIN_ACCESS`).
- Password: `Auth::hashPassword` = `sha1(md5(plain . salts.site))` — keep compatible with legacy.

## EventBus conventions

- `EventBus::emit($event, $payload)` returns mutated payload; listeners must `return $payload`.
- Prefer array payloads with clear keys; set `error` / `handled` / `html` / `redirect` as established by existing subscribers.
- Document events in `module.json` → `hooks.publish` / `hooks.subscribe`.
- Important existing events: `core.dashboard.catalog`, `core.dashboard.widgets`, `core.notices.collect`, `core.news_item.*`, `comments.prepare_add`, `comments.form.*`, `comments.render_actions`, `comments.counts`, `core.auth.after_login`, `core.user_edit.*`, `core.bulk.check_item`, `core.settings.sections`, `core.settings.save`.

## Migrations

- Filename sorted lexicographically; basename without `.php` = migration id.
- Idempotent where possible; stop module on first error (`SchemaMigrator`).
- Run: `php bin/schema-migrate.php` / `php bin/data-migrate.php`.
- Enabling a module via UI runs that module’s schema migrations first.

## Existing modules (do not reinvent)

| id | Role |
|----|------|
| `core` | Auth, home, users, statuses, news/tickets, settings, modules UI, migrate |
| `comments` | Comment thread on news items; no own menu routes |
| `contracts` | Offers/contracts; depends on `core` + `comments` |
| `clients` | Client CRM (`base_clients`) + client statuses; login identity = phone |
| `units` | Units of measure (`base_units`, shared with oper) |
| `suppliers` | Suppliers (`base_suppliers`, shared with oper) |
| `cabinets` | Rooms (`base_cabinets`); used by stocks `ID_CABINET` and staff `skin` |
| `tables` | Massage tables (`base_tables`); optional — without it `ID_TABLE` defaults to `1` |
| `workweeks` | Day shifts (`base_workweeks`); menu under Team; embedded in calendar day |
| `catalog` | Categories, products, product relations; stock qty in `stocks`/`stickers` |
| `stocks` | Warehouses (`base_stocks`) + leftovers report |
| `stickers` | Receipt/writeoff acts + sticker ins/outs + writeoff service |
| `norms` | Default consumptions (`base_good_defaults`); embed on catalog product edit |
| `sheets` | LikeSpa linen accounts/moves (`base_sheet_*`); disableable |
| `procedures` | Procedure types + journal (`base_procedure_types`, `base_procedures`); restores UI later (EventBus → `payments`) |
| `orders` | Website orders (`base_orders`, shared with site/oper); notice chips; `orders.for_day` for calendar |
| `calendar` | Day view + event CRUD (`base_events*`); requires `workweeks`; can create procedures from event |
| `rates` | Specialist commission rates (`base_procent_history`); requires `procedures` |
| `procents` | Read-only commission accrual report; requires `rates` + `procedures` + `workweeks` |
| `payments` | Staff settlements (`base_payments`, `base_payment_types`); contracts pay CTA; auto-DPS when `ledger` on |
| `vacations` | Leave register (`base_vacations`) + sign workflow |
| `ledger` | Accounts + move types + ДПС + transfers + reconciliations (`base_accounts*`) |
| `funds` | Fund tree + CAPUSE/REFUND movements (`base_funds*`); requires `ledger` |
| `finance` | Balance/ODDS report (no inflation, no amort schedule); soft `funds` for FUND_MATRIX |

Prefer extending these via hooks before forking page logic.

## Apache / paths

- `.htaccess` forces `/modules` into `index.php` (filesystem `modules/` would 403), **except** existing files under `modules/*/assets/` with extensions `css|js|png|jpe?g|gif|svg|webp|woff2?`.
- `app|cfg|bin|docs` are forbidden from the web.

## Testing / verify locally

- Prefer CLI migrators after schema changes.
- Smoke the affected route in browser if UI changed.
- Keep StageTiming marks only if already used on that page path.

## Out of scope unless asked

- Rewriting legacy oper in place.
- Composer frameworks, React SPA, new build tooling.
- Force-push, amend, or commits without explicit user request.
