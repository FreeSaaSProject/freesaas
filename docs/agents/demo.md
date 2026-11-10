# Demo stands

One FreeSaaS git; demo behaviour is **cfg flags** + module `demo` on a dedicated deploy. Production leaves flags unset/`0` and `demo=0`.

Thin guards stay in core (`app/DemoMode.php`: demo-host marker, HTTP lock, fail-closed modules, auto-login). Fat seed/provision live in **`modules/demo`** (profiles `spa` | `clinic`).

## Flags (`cfg/config.ini` `[app]`)

| Key | Default | Effect when `1` |
|-----|---------|-----------------|
| `demo_readonly` | off | **Demo-host marker**: fail-closed modules, `noindex`, CLI `demo *` allowed. HTTP mutation policy is separate — see `[demo] http_lock` below. |
| `demo_auto_login` | off | After bootstrap, if no session → `Auth::establishSession` for `demo_auto_login_user` |
| `demo_auto_login_user` | `demo` | Login used by auto-login |
| `currency` | `RUB` | Platform ISO 4217 (Settings). demoru=`RUB`, demoen=`USD`. |

### HTTP lock (`cfg/modules/demo.ini` `[demo] http_lock`)

Requires `app.demo_readonly=1`. Default `full` (public marketing stands).

| Value | Behaviour |
|-------|-----------|
| `full` | Deny `POST\|PUT\|PATCH\|DELETE` except `/login`, `/logout`, `/locale`, `/cron/*`, `/agent/*` |
| `auth` | Same deny for guests; signed-in staff mutate under normal `Auth::can`. Use for closed AI demos (`agent_token` + optional `llm`) |
| `off` | No demo-specific HTTP mutation block (still noindex + fail-closed modules) |

Closed AI preset: `demo_readonly=1`, `http_lock=auth`, `show_promo=0`, `demo_auto_login=0`, real `agent_token`, enable `llm` / staff MCP as needed. Public spa preset: `http_lock=full`, `show_promo=1`, leave `agent_token` empty/`CHANGE_ME`.

Other module ini keys: `stand_id`, `profile` (`spa`\|`clinic`), `show_promo`, `frozen_at`, banner URLs — see below.

### Promo chrome (`show_promo`)

| Value | Behaviour |
|-------|-----------|
| `1` (default) | Banner CTAs («Get this» / freesaas.ru), per-screen Max tip cards, RU↔EN switch chip |
| `0` | Hide those marketing placards; keep a short demo-status strip (readonly / limited access) without CTA buttons |

**Indexing:** `index.php` (admin) and `layout_auth` always send `noindex`. Public storefront (`site.php` / theme layouts) stays indexable — do not put `demo_readonly` on a customer vitrine host if you want SEO there.

Leave `agent_token` empty/`CHANGE_ME` on public stands if you do not need `/agent/*` after bootstrap; keep `cron_token` for `/cron/tick`. Mutation audit: [`docs/demo-audit.md`](../demo-audit.md) (`php bin/check-demo-mutations.php`).

## Module

```ini
[modules]
demo=1
```

Banner strip (CTA / home / tips) + CLI `demo seed` / `demo provision` require the module enabled. Ini: `stand_id`, `profile`, `http_lock`, `cta_url`, `home_url`, `tips_base_url`, `switch_url_*`, `frozen_at`, `reseed_at`, `cors_origin`. CTA UTM **before** `#` (`…/?utm_…#start`); EN stand → `freesaas.ru/en/…`. Public `GET /demo-modules.json` (CORS) for marketing tiles. On production keep `demo=0`.

### Seed profile (`profile`)

| Value | Narrative | Engine |
|-------|-----------|--------|
| `spa` (default) | Dream salon | `DemoSeed` wipe+fill |
| `clinic` | Harmony clinic | `modules/demo/lib/ClinicDemo/*` staged seed |

CLI override: `php bin/freesaas.php demo seed --profile=clinic --locale=ru --reset`. Dual ru/en hosts are optional; one stand with `app.locale` is enough.

### Frozen business clock (`frozen_at`)

Optional. When set (and `demo_readonly=1`), `DemoMode::now()` / `dayStart()` / `dayEnd()` return that instant (parsed in `app.timezone`) for **seed + “today” widgets** (calendar home, orders notices). Auth, Cron, and sessions keep real `time()`.

- Example: `frozen_at = "2026-10-22 14:00:00"` (mid-week afternoon keeps home widgets filled).
- Banner appends a localized “clock frozen” note with the formatted datetime.
- Daily `demo.reseed` jobs are **not registered** while frozen (calendar drift no longer applies). Keep `reseed_on_version=1` so a code bump of module `demo` still reseeds once.
- Empty / unset → live wall clock + scheduled reseed as before.

## Prod safety (two independent locks)

1. **cfg flags:** `demo provision` / `demo seed` / demo cron jobs **refuse** unless `demo_readonly=1` and `demo=1`. Absent or `0` → `DemoMode::readonly()` is false. `Config::moduleEnabled` treats **absent `[modules]` keys as off** everywhere (not demo-only). Never set demo flags on customer instances.
2. **DB marker:** table `base_demo_stand` (owned by `demo`, one row). Seed and wipe run only when its `STAND_ID` equals `[demo] stand_id` in `cfg/modules/demo.ini`.
   - A **fresh** DB (0 rows in `base_clients`, `base_events`, `base_news`) is claimed automatically on the first seed.
   - A DB with data is never claimed implicitly: `demo claim` refuses. For a known demo copy only: `php bin/freesaas.php demo claim --existing`.
   - So flags copied onto production, or a production dump loaded into a demo DB, still cannot `DELETE` anything.

Each seed also: checks installed module versions against `modules/demo/module.json` `requires` / `optionalRequires` **before** wiping (preflight; mismatch → refuse, data kept), and dumps the touched tables first (`var/db_dumps`, last `keep_dumps` kept). If the seed throws, that dump is restored — the stand shows the previous data, never an empty one.

**Rule when enriching the seed:** if the seed starts using new API/columns of module X, bump X (`module bump X`) and raise `optionalRequires.X` in `modules/demo/module.json` to that version.

## What http_lock blocks

- **HTTP (`full`):** `POST|PUT|PATCH|DELETE` except `/login`, `/logout`, `/locale`, `/cron/*`, `/agent/*` (`DemoMode::denyUnsafeIfReadonly`).
- **HTTP (`auth`):** same for anonymous; authenticated sessions may mutate (permissions still apply).
- **HTTP (`off`):** no demo mutation deny.
- **GET side effects:** `DemoMode::blocksHttpSideEffects()` — under `full` always on HTTP; under `auth` only for guests; under `off` never. Call sites no-op (e.g. `NewsRepository::markViewed`).
- **Does not** grant `SUPERADMIN_ACCESS`. Demo Viewer is provisioned without it; calendar review chips are an intentional demo exception (`DemoMode::readonly()`), not a privilege escalation.
- **CLI** seed/provision still write while `demo_readonly=1` (`blocksHttpSideEffects` is false under `cli`).

## Provision guest

```bash
# After migrate + modules allowlist + demo_readonly=1 + demo=1
php bin/freesaas.php demo provision --login=demo --password=demodemo --format=json
```

Creates/updates role **Demo Viewer** (no `SUPERADMIN_ACCESS`) and user with `core.home`, `*.view`, `core.news`, plus demo ACL flags (`SHOW_ADMINISTRATOR_DATA`, `CAN_SEE_SUM_OTHERS`, …). Mutations still blocked by Router.

## Self-maintenance without CLI (cron)

The seed is code (`modules/demo/lib/DemoSeed.php`), so "update the seed" = "ship a new `demo` module version". Three jobs are registered **only** when `demo_readonly=1` (production never lists them). Host wakes them with one line:

```cron
*/5 * * * * curl -sS -H "X-FreeSaaS-Agent-Token: CRON_TOKEN" "https://demoru.example.com/cron/tick" >/dev/null
# or, if the panel runs commands: php /path/bin/freesaas.php cron tick
```

| Job | When | Does |
|-----|------|------|
| `demo.update` | `update_at` or `update_every_min` | `UpdateEngine` check + apply (`update_enabled=1`, needs `cfg/updates.ini`) |
| `demo.reseed` | `reseed_at` or `reseed_every_min` | `demo seed --reset` with `app.locale` (dates stay "today"). **Skipped when `frozen_at` is set.** |
| `demo.version_check` | every `version_check_every_min` | reseed when `modules/demo` version ≠ last seeded (`reseed_on_version=1`) |

Schedule lives in `cfg/modules/demo.ini` (copy `modules/demo/config.example.ini`); read on every request, so edits apply from the next tick. `*_every_min > 0` overrides `*_at`; invalid values fall back to defaults and are reported in the job message. Precision = host tick step. Journal + schedule: `/cron` (admin) or `GET /cron/status` (cron token).

`update_target`: `all` (default — whole code, versions consistent) or `demo` (seed only). With `demo`, the job still checks every module and **escalates to `all`** when any module listed in demo `requires` / `optionalRequires` has an update. Reseed after an update happens in the next tick (`demo.version_check`) — the updating process still holds the old seed class.

Seed enrichment workflow: edit `DemoSeed.php` → seed local stands → `php bin/freesaas.php module bump demo` → push `main` → stands update at `update_at` and reseed via `demo.version_check`.

Status: `php bin/freesaas.php demo status --format=json` (marker, versions, preflight, effective schedule).

## Update code manually (CLI)

```bash
php bin/freesaas.php update check --format=json
php bin/freesaas.php update apply --target=all --format=json
```

Overlay never writes `cfg/`, `var/`, uploads. After apply: confirm new `modules/<id>` stay `=0` unless intentionally enabled (including keeping `demo=1` on the stand).

## Sample data manually (relative to today / frozen_at)

```bash
php bin/freesaas.php migrate run --module=demo --format=json   # base_demo_stand
php bin/freesaas.php demo seed --locale=ru --reset --format=json
php bin/freesaas.php demo seed --locale=en --reset --format=json
# Clinic profile (Harmony):
php bin/freesaas.php demo seed --profile=clinic --locale=ru --reset --format=json
```

Spa profile fills cabinets, catalog, clients, staff, calendar, ledger/finance, audio demo, rates/procents, etc. Clinic profile runs staged `ClinicDemo` (structure → clinical → money → forward → docs → audio → feed). `feed` fills the home feed (articles, tickets with threads, personal messages, likes, favorites; creates ticket statuses 1/2/3/99 if missing); `--only=feed --force` replaces only its own rows. `--reset` clears prior demo domain rows (spa wipe tables / clinic manifest clean). Domain dates follow `DemoMode::now()` (wall clock, or `frozen_at` when set).

`bin/demo-restore.example.sh` (host crontab calling `demo seed`) remains as a CLI fallback; with `cron_token` + `demo.reseed` it is not needed.

## Suggested `[modules]` on demo

On for a spa product tour: `demo`, `core`, `comments`, `clients`, `catalog`, `calendar`, `workweeks`, `cabinets`, `procedures`, `orders`, `ledger`, `finance`, `funds`, `rates`, `procents`, `payments`, `vacations`, `stocks`, `stickers`, `units`, `suppliers`, `updates` (CLI apply), plus whatever audio/BI pack you show.

Clinic profile additionally wants: `docs`, `audio` + `audio_dicts` (+ soft `audio_pipeline`), `payments`, `rates`.

Public spa: force **off** `llm`, `assistant`, `client_mcp`, `bots`, `terminals`, `asr`, `banks`, `yclients`, `clientics`, `sites` (or accept cart writes), `likespa` legacy APIs, `booking` if you do not want public books.

Closed AI clinic stand (`http_lock=auth`): may enable `llm` / staff MCP and set a real `agent_token`; keep `demo_auto_login=0`.

List **every** discovered module explicitly (`=0`/`=1`) so absent-key fail-closed never surprises you after a full zip apply.

## Two locales

Two deploys of the same release: e.g. `demoru` / `demoen` with `app.locale=ru|en` and separate DBs/seeds. UI chrome follows locale; DB `NAME`/UGC does not — use language-matched golden data.

Cross-link the stands from the demo banner: `[demo] switch_url_ru` / `switch_url_en` in `cfg/modules/demo.ini` (the link to the *other* language is shown). One host serving two DBs is intentionally not supported.
