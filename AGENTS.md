# AGENTS.md — FreeSaaS (likespa)

Instructions for coding agents working in this repository. Human guide: [`docs/GUIDE.md`](docs/GUIDE.md). Table ownership: [`docs/TABLE_MAP.md`](docs/TABLE_MAP.md). Agent-native audit and platform/module requirements: [`docs/agent-native-audit.md`](docs/agent-native-audit.md). Operation runbooks: [`docs/agents/`](docs/agents/). Quick path: [`docs/agents/QUICKSTART.md`](docs/agents/QUICKSTART.md). Auth surfaces: [`docs/agents/agent-surfaces.md`](docs/agents/agent-surfaces.md).

**License / contributions:** AGPL-3.0-or-later ([`LICENSE`](LICENSE)); optional commercial terms ([`COMMERCIAL-LICENSE.md`](COMMERCIAL-LICENSE.md)). External PRs require a CLA — [`CONTRIBUTING.md`](CONTRIBUTING.md) / [`CLA.md`](CLA.md).

## Stack

- PHP 7.4+/8.x style with `declare(strict_types=1);`, no Composer framework.
- Front controllers: `index.php` (admin) / `site.php` (storefront) → `app/bootstrap.php` → `Router::dispatch()`. Role: `AppRuntime` + optional `module.json` `runtime` (`admin`\|`storefront`\|`both`).
- MySQL via PDO (`app/Database.php`). Platform config: `cfg/config.ini`; module settings: `cfg/modules/<id>.ini`.
- Modules under `modules/<id>/` with `module.json` + `bootstrap.php`.
- Agent/ops CLI: `php bin/freesaas.php` (see GUIDE CLI / `docs/agent-native-audit.md`). First install: `bootstrap` or `POST /agent/bootstrap` ([`docs/agents/install.md`](docs/agents/install.md)). HTTP twin for shared hosting: `/agent/*` + `app.agent_token` ([`docs/agents/remote-api.md`](docs/agents/remote-api.md)). Smoke: `php bin/smoke.php`.

## Hard rules (do not violate)

1. **Runtime SQL only against `base_*` tables** (and `information_schema` probes). Do not invent a second table-prefix family. Own tables go in the module’s `module.json` → `tables` and additive migrations.
2. **New tables / columns use `base_*`.** Prefer additive migrations (`CREATE IF NOT EXISTS`, `ALTER` with existence checks).
3. **Do not invent a second routing/DI layer.** Register routes in module `bootstrap.php` via `ModuleManager::registerRoute`. Use `View::render`, `EventBus`, existing repositories.
4. **Cross-module UI/behavior goes through EventBus**, not by hard-coupling pages of another module when a hook already exists (see `hooks` in each `module.json`).
5. **UI chrome via i18n (mandatory for every module):** use `t('module.key')` / `te()` / `tj()`; catalogs in `modules/<id>/lang/{ru,en}.php` (and `app/lang/` for shared). New modules ship both locales from day one. Do not hardcode UI language in views/pages/`module.json` labels. English for code identifiers; short focused diffs; no drive-by refactors or unsolicited markdown docs. UGC and DB status `NAME` values are not translated.
6. **Do not commit secrets** (`cfg/config.ini`, `cfg/modules/*.ini` with real passwords). Platform keys go in `config.example.ini`; product keys in `modules/<id>/config.example.ini` only.
7. **Do not disable or remove `core`.** Module enable/disable is via `Config::setModuleEnabled` / UI `/modules` / `[modules]` in ini.
8. **Do not hardcode product module UI/config into `app/` or core views.** Rail group weights: `ModuleManager::registerMenuGroup`. Module CSS/JS: `Assets::register`. Staff edit fields: `EventBus` `core.user_edit.sections` / `save` / `profile`. Role flags: `StatusesRepository::registerFieldGroup`. Module ini sections: `cfg/modules/<section>.ini` (not new blocks in `config.example.ini`). Shared `core.menu.group.*` weights only in `modules/core/bootstrap.php`.
9. **Least privilege for staff roles.** `SUPERADMIN_ACCESS` is a bypass, not a permission set: it skips every `Auth::can()` check. Give it to the director / first admin only — never to product staff, and never copy it into a new role. Ordinary admin roles get explicit `permissions[]` on the user plus the flags they actually use (`CONTROL_ACCESS`, `EVT_ASSIGN`, `CAN_BE_ASSIGNED`, `SHOW_ADMINISTRATOR_DATA`, …). Screens gated with `'capability' => 'SUPERADMIN_ACCESS'` (module settings, statuses/roles, updates, OAuth/MCP) stay director-only by design. Staff pickers must use `UsersRepository::listEnabledStaff()` / `staffNamesIncluding()`; `listForCopy()` is only for copyFrom templates and intentionally hides `SUPERADMIN_ACCESS` roles.
10. **One place for module versions (updates).** UpdateEngine reads only `modules/<id>/module.json` → `version` (platform = `modules/core/module.json`). Prefer `php bin/freesaas.php module bump <id>` (`--patch` default, or `--minor` / `--major` / `--set=x.y.z`). Do **not** edit root `VERSION` by hand (derived copy of core, synced by bump/release). Do not bump versions inside `modules-catalog.json` manually.

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
| Shared platform service | `app/*.php` (only if truly cross-module; prefer module lib otherwise). Examples: `ListQuery` (pager + column sort), `I18n`, `Cron` (hard tick registry). |
| Scheduled background job | `Cron::register` in module `bootstrap.php` (`every` or `daily_at` + handler). Host: one `*/5` → `/cron/tick` (AgentAuth) or `php bin/freesaas.php cron tick`. Journal UI `/cron`. See [`docs/agents/cron.md`](docs/agents/cron.md). Do not add per-task host crontab lines. |
| UI translation strings | `modules/<id>/lang/{ru,en}.php` or `app/lang/` for shared chrome; keys like `core.layout.logout` |
| New product feature | New or existing `modules/<id>/` |
| HTTP endpoint | `ModuleManager::registerRoute` in that module’s `bootstrap.php` + `pages/` |
| HTML for a page | `modules/<id>/views/` + `View::render($name, $data, 'layout', $moduleId)` |
| SQL / domain logic | `modules/<id>/lib/*Repository.php` |
| Schema change | `modules/<id>/migrations/NNN_description.php` returning `function (PDO $pdo): void` |
| One-time data seed/import | `modules/<id>/migrators/<id>.php` + entry in `module.json` `migrators` |
| Extend home widgets / news card / comment form | `EventBus::on(...)` in `bootstrap.php` |
| List row selection / group ops | `BulkActions::register($listId, $action, …)` in bootstrap; view helpers `th`/`td`/`bar`; dependency blocks via `EventBus::on('core.bulk.check_item')` |
| Breadcrumb trail UI | Page passes `breadcrumbs` (+ optional `breadcrumbs_aria`) to `View::render`; layout always draws them at top of `<main>` via `Breadcrumbs` (`modules/core/lib/Breadcrumbs.php`). If omitted, auto from menu + page title. Ancestor hrefs get `NavContext::up()` (keep list filters when `ret` path matches). Layout shows `[Return]` via `NavContext::returnUrl()` when `ret` is set. |
| Return / list filters | Automatic via `NavContext` (`app/NavContext.php`): `View::enrichContent` on render, `redirect()` → `redirectUrl()`, breadcrumbs `[Return]`, `core.js` for dynamic links. Modules should not wire `ret=` manually. Opt-out: `data-nav-no-ret`. Do not invent per-module open-redirect helpers. |
| Module CSS/JS | `modules/<id>/assets/{css,js}/` + `Assets::register($id, …)` in bootstrap. `View::render(..., $id)` auto-queues; embeds call `Assets::need('other')`. Core chrome: `assets/css/core.css`, `assets/js/core.js`. |
| Product JSON for agents | Optional `module.json` → `agent.ops[]` (id/method/path/permission/description). Same routes as UI; respond when `AgentHttp::wantsJson()` via `AgentHttp::emit` / `error`. Session + module permissions — not platform `/agent/*`. See [`docs/agents/module-agent-api.md`](docs/agents/module-agent-api.md). |
| Rail menu group order | Owning module: `ModuleManager::registerMenuGroup('myid.menu.group', 35)` in bootstrap (same key as `menu[].group`). Optional 3rd arg = section landing path (title click). Shared chrome groups (`core.menu.group.*`) only in `modules/core/bootstrap.php`. Never append product keys to `app/ModuleManager.php`. Same translated group title merges into one rail section. Within a section, optional `menu[].kind`: `work` (default) → `crud` → `settings` (paths like `/module/settings` auto-`settings`), then `num`. |
| Staff user edit fields | `EventBus::on('core.user_edit.sections'|'save'|'profile')` in owning module; do not add product checkboxes to `modules/core/views/user_edit.php`. |
| Role status flag groups | `StatusesRepository::registerFieldGroup([...])` in owning bootstrap (`when` = module id). Core keeps only `when=always` groups. |
| Module config / secrets | Live: `cfg/modules/<id>.ini` (gitignored). Example: `modules/<id>/config.example.ini`. Platform only in `cfg/config.ini` (`connect`/`app`/`salts`/`modules`). `Config::get/set` unchanged for callers. |

Layouts always live in `modules/core/views/` (`layout`, `layout_auth`, `error`).

## Module checklist (new module)

Create `modules/<id>/` with:

1. `module.json` — required keys: `id`, `name`, `version`, `type` (`product`), `requires` (at least `"core": ">=0.3.0"`), `permissions`, `menu` (if nav), `tables`, `hooks` (document emit/subscribe), `routes.pages` (list paths for humans; real routes are in bootstrap). Recommended: `description` (i18n key `{id}.module.description`), `catalog` (`categories[]` many-to-many / `maturity`=`alpha|beta|release` / `tags` / `screenshots` — allowlists in `app/ModuleCatalog.php`). Optional: `agent.ops` for product JSON ([`docs/agents/module-agent-api.md`](docs/agents/module-agent-api.md)).
2. **i18n (required):** `lang/ru.php` + `lang/en.php` with all UI chrome strings. `module.json` `name`, `description`, `menu[].title|group`, and `permissions[].label` must be translation **keys** (e.g. `myid.menu.list`), not hardcoded language text. Also ship `{id}.module.summary` (when to enable / deps in words) for the modules catalog and agents. Views/pages/bootstrap/lib labels use `t()` / `te()` / `tj()` — never hardcode Russian or English UI copy.
3. `bootstrap.php` — `require_once` libs; `Assets::register` if CSS/JS; `ModuleManager::registerMenuGroup` if own rail group; `StatusesRepository::registerFieldGroup` if role flags; register routes; subscribe EventBus (incl. `core.user_edit.*` when adding staff fields).
4. Optional: `pages/`, `views/`, `lib/`, `migrations/`, `assets/{css,js}/`, `assets/catalog/` screenshots, `config.example.ini`.
5. Enable: `[modules] <id>=1` or Modules catalog UI `/modules` (runs schema migrations on enable). Copy module config example → `cfg/modules/<id>.ini` if the module needs ini settings.
6. New product module in the monorepo: regenerate and commit `modules-catalog.json` (`php bin/build-modules-catalog.php`) so other instances can see it under **Modules → Available to install** (UpdateEngine).

Do **not** translate UGC or DB reference `NAME` fields; only chrome (buttons, flashes, menu, column headers, empty states, JS prompts).

Route registration shape:

```php
ModuleManager::registerRoute('/path/{id}', [
 'module' => 'myid',
 'methods' => ['GET', 'POST'],
 'permission' => 'myid.view', // or 'public' => true; optional 'capability' => 'SUPERADMIN_ACCESS'
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
        ['label' => t('myid.menu.list'), 'current' => true],
        // deeper levels when needed:
        // ['label' => $folderName, 'current' => true],
    ]),
    'breadcrumbs_aria' => t('myid.folder_aria'), // optional
], 'layout', 'myid');
```

Do **not** call `Breadcrumbs::render` inside module views — chrome owns placement. If `breadcrumbs` is omitted, layout auto-builds `Home / {best menu match} / {page title}` so every screen still has a path.

On an **exact menu list path**, layout automatically turns the leaf crumb into a link to the clean list URL whenever the query is non-empty (filters, pager, sort — ignoring `ret`, empty values, `page=1`). Modules do not opt in; omit query on the default list URL so the crumb stays plain text.

Item keys: `label` (required), `href` (optional), `current` (optional). Ancestors stay links.

## Module maturity (alpha / beta / release)

FreeSaaS is an **open** system: source in the repo + an agent with tools. Unlike classical SaaS (hide internals, polish until the user cannot self-repair), an imperfect module can still have practical value early.

| Maturity | Meaning | Enable |
|----------|---------|--------|
| `alpha` | Experimental; expect instability | UI risk panel; agent/CLI needs `accept_risk` / `--accept-risk` |
| `beta` | Mostly usable; rough edges remain | same risk ack |
| `release` | Stability promised | normal enable |

**Agent note (alpha):** «Alpha — expect instability; be ready to diagnose and repair.»

**When an alpha/beta module fails**, do **not** stop at «HTTP 500, contact an administrator». Diagnose, repair locally when possible, then offer upstream. Example tone: «Module is experimental. Found a migration error after update. Fixed locally, tests pass. Create an issue/PR to the main repo?»

**Policy (also on `GET /agent/modules` as `maturity_policy`):**

- **Alpha/Beta:** agent may diagnose and propose a local fix. If the bug is in FreeSaaS code and reproducible — check existing issues; if no duplicate, create an issue. If the agent fixed it and tests pass — attach a patch/PR to the issue.
- **Release:** same process, but the bug gets **higher priority** because the stability promise was broken.

Declare `catalog.maturity` in `module.json`. Catalog UI badges + filter; list/inspect expose `maturity`, `requires_risk_ack`, `agent_note`.

## Auth

- Session name `freesaas`. Keys: `user_id`, `login`, `user_name`, `permissions`, `status_id`, `status_flags`.
- Route gate: `Auth::can('module.perm')` (superadmin always true).
- Menu visibility: `Auth::canSeeInMenu('module.perm')` — personal checkboxes even for superadmin (hide unused modules from the rail).
- Role flags from `base_admin_statuses`: `Auth::capability('FLAG_NAME')` (e.g. `CONTROL_ACCESS`, `SUPERADMIN_ACCESS`).
- Password: new writes use `password_hash` (argon2id/bcrypt). Legacy rows `sha1(md5(plain . salts.site))` still verify; rehash-on-login upgrades them. Never rotate `salts.site`. Lockout via `retries` + `locked_until`; unlock/login-link/logout-all: `php bin/freesaas.php user …`.
- Sessions: `Auth::check()` revalidates `enabled` / `auth_epoch` / permissions (throttled ~60s; always on POST and capability routes). Bump `auth_epoch` on password/role/enabled/permission changes. Idle: `session_idle_minutes` (default 120); remember cookie: `session_remember_days`.

## EventBus conventions

- `EventBus::emit($event, $payload)` returns mutated payload; listeners must `return $payload`.
- Prefer array payloads with clear keys; set `error` / `handled` / `html` / `redirect` as established by existing subscribers.
- Document events in `module.json` → `hooks.publish` / `hooks.subscribe`.
- Important existing events: `core.dashboard.catalog`, `core.dashboard.widgets`, `core.notices.collect`, `core.layout.gate` (layout chrome: `blocked`/`html`/`chrome`/`js_i18n` — no product keys in core), `core.news_item.*`, `comments.prepare_add`, `comments.form.*`, `comments.render_actions`, `comments.counts`, `core.auth.after_login`, `core.auth.before_logout`, `core.user_edit.*`, `core.bulk.check_item`, `core.settings.sections`, `core.settings.save`. Product module settings live on `/<id>/settings` (superadmin `capability`); sibling modules may extend via `<id>.settings.sections` / `.save` (e.g. `audio.settings.*`).

## Migrations

- Filename sorted lexicographically; basename without `.php` = migration id.
- Idempotent where possible; stop module on first error (`SchemaMigrator`).
- Run: `php bin/freesaas.php migrate run` (or `--module=id`); legacy: `php bin/schema-migrate.php` / `php bin/data-migrate.php`.
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
| `likespa` | Spa instance pack: flyer + promoter discounts (`/likespa/settings`), term scans, promoter bonus + dops on `/audio/calendar`; procedure restores + settlement (ACCOUNT=0); staff public profile; disableable. CMS moved to `sites`. |
| `sites` | Storefront + CMS: multi-host (`base_site_hosts`), SEO, articles, promo, redirects, themes (`site.php`); shop/cart + booking wizard; agent diagnose `GET /sites/status` — [`docs/agents/sites.md`](docs/agents/sites.md); themes: [`docs/agents/themes.md`](docs/agents/themes.md) |
| `booking` | Slot engine + advance order book (`base_orders`); UI `/booking/new` in Calendar rail (not event slot-click); alpha; agent `/booking/availability` `/booking/book`; storefront `/cart` wizard when enabled — [`docs/agents/booking.md`](docs/agents/booking.md). `/t` later |
| `terminals` | Device registry + bracelets + therm occupancy/orders; 1C/volgatermy sync jobs (`cfg/modules/terminals.ini`); disableable |
| `staff_reports` | Staff quality day reports (traffic lights by position); rewards on `/audio/calendar`; generate via Re-® / `/staff-reports/generate` |
| `procedures` | Procedure types + journal (`base_procedure_types`, `base_procedures`, `base_procedure_restores` schema); restore UI lives in `likespa` |
| `orders` | Website orders (`base_orders`, shared with site/oper); notice chips; `orders.for_day` for calendar |
| `polls` | Poll definitions tree (`base_polls`); universal; channels via EventBus |
| `poll_invites` | Poll invite instances + staff fill (`base_poll_invites`); requires `polls`; publish `poll_invites.*` for bots/site |
| `calendar` | Day view + event CRUD (`base_events*`); requires `workweeks`; can create procedures from event |
| `rates` | Specialist commission rates (`base_procent_history`); requires `procedures` |
| `procents` | Read-only commission accrual report; requires `rates` + `procedures` + `workweeks` |
| `asr` | Voice queue + shared Whisper worker token (`/asr/voice-transcribe/*`); requires `core` only; soft `bots` |
| `audio` | Cabin records + badges + FileStorage; soft `workweeks` |
| `audio_dicts` | Types/dicts/keys/scripts/trash for recognition; requires `audio` |
| `audio_pipeline` | Cabin ingest/ASR/stem/collect workers; requires `asr` + `audio` + `audio_dicts` |
| `audio_reports` | Records calendar + summary reports; requires `audio` + `audio_dicts` + `workweeks` |
| `llm` | OpenAI-compatible LLM client + token profiles UI (`cfg/modules/llm.ini`); shared by studies/bots/agents |
| `bots` | Telegram/Max transport + `base_telegram_sends` queue + `base_bot_links` identities + webhooks; domain adapters via `BotPlugins` / `bots.send` |
| `audio_llm` | LLM studies (`base_records_llm_studies*`); cyclic templates + staff day-report spawn; requires `llm` + `audio` |
| `payments` | Staff settlements (`base_payments`, `base_payment_types`); contracts pay CTA; auto-DPS when `ledger` on |
| `bonuses` | Client loyalty ledger (`base_bonuspay`) + event settle (bonusAnal/apply); EventBus on calendar event edit |
| `vacations` | Leave register (`base_vacations`) + sign workflow |
| `ledger` | Accounts + move types + ДПС + transfers + reconciliations (`base_accounts*`) |
| `funds` | Fund tree + CAPUSE/REFUND movements (`base_funds*`); requires `ledger` |
| `finance` | Balance/ODDS report (no inflation, no amort schedule); soft `funds` for FUND_MATRIX |
| `liquid_assets` | Liquid assets report + T-Invest accounts/sync (broker snapshots); requires `ledger`; soft `funds` for REVAL; disableable |
| `banks` | Bank OAuth/token (Tochka, Alfa, T-Bank, Sber — UL/FL) → link `base_accounts`, sync statements into ДПС; soft `llm` classify; disableable |

Prefer extending these via hooks before forking page logic.

## Apache / paths

- `.htaccess` forces `/modules` into `index.php` (filesystem `modules/` would 403), **except** existing files under `modules/*/assets/` with extensions `css|js|png|jpe?g|gif|svg|webp|woff2?`.
- `app|cfg|bin|docs` are forbidden from the web.

## Testing / verify locally

- After schema or module changes: `php bin/smoke.php` (system + locale + module validate + health + `check-safe-html` / `check-csrf` / `check-password` / `check-session` / `check-bots-webhook`).
- Prefer CLI: `php bin/freesaas.php module validate <id>`, `health`, `locale validate`.
- Before PR (also in `.github/workflows/ci.yml`): `php -l` on changed PHP, `bin/check-i18n.php`, `bin/check-routes.php` (public-route allow-list; enable all modules in `cfg/config.ini` first), plus the `bin/check-*.php` helpers above.
- Smoke the affected route in browser if UI changed.
- Keep StageTiming marks only if already used on that page path.
- Lifecycle: uninstall is **disable-only** (tables remain); see agent-native-audit.

## Out of scope unless asked

- Rewriting legacy oper in place.
- Composer frameworks, React SPA, new build tooling.
- Force-push, amend, or commits without explicit user request.
