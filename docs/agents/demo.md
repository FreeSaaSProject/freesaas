# Public demo stands

One FreeSaaS git; demo behaviour is **cfg flags** + module `demo` on a dedicated deploy. Production leaves flags unset/`0` and `demo=0`.

Thin guards stay in core (`app/DemoMode.php`: readonly Router, fail-closed modules, auto-login). Fat seed/provision live in **`modules/demo`**.

## Flags (`cfg/config.ini` `[app]`)

| Key | Default | Effect when `1` |
|-----|---------|-----------------|
| `demo_readonly` | off | Deny `POST\|PUT\|PATCH\|DELETE` except `/login`, `/logout`, `/locale`. Missing `[modules]` keys → **disabled** (fail-closed). |
| `demo_auto_login` | off | After bootstrap, if no session → `Auth::establishSession` for `demo_auto_login_user` |
| `demo_auto_login_user` | `demo` | Login used by auto-login |

Leave `agent_token` empty/`CHANGE_ME` (blocks `/agent/*` and `/cron/tick` over HTTP).

## Module

```ini
[modules]
demo=1
```

Banner notice and CLI `demo seed` / `demo provision` require the module enabled. On production keep `demo=0`.

## Prod safety

- Absent or `0` → `DemoMode::readonly()` is false; `Config::moduleEnabled` keeps historic **absent = on**.
- `demo provision` / `demo seed` **refuse** unless `demo_readonly=1` and `demo=1`.
- Never set these flags on customer instances.

## What readonly blocks

- **HTTP:** `POST|PUT|PATCH|DELETE` except `/login`, `/logout`, `/locale` (`DemoMode::denyUnsafeIfReadonly`).
- **GET side effects:** shared writes from page views must call `DemoMode::blocksHttpSideEffects()` and no-op (e.g. `NewsRepository::markViewed` — otherwise every guest flips unread for the shared `demo` user). Session/cookie-only changes are fine.
- **Does not** grant `SUPERADMIN_ACCESS`. Demo Viewer is provisioned without it; calendar review chips are an intentional demo exception (`DemoMode::readonly()`), not a privilege escalation.
- **CLI** seed/provision still write while `demo_readonly=1` (`blocksHttpSideEffects` is false under `cli`).

## Provision guest

```bash
# After migrate + modules allowlist + demo_readonly=1 + demo=1
php bin/freesaas.php demo provision --login=demo --password=demodemo --format=json
```

Creates/updates role **Demo Viewer** (no `SUPERADMIN_ACCESS`) and user with `core.home`, `*.view`, `core.news`, plus demo ACL flags (`SHOW_ADMINISTRATOR_DATA`, `CAN_SEE_SUM_OTHERS`, …). Mutations still blocked by Router.

## Update code

Same UpdateEngine as other instances — **CLI only** on the demo host (no HTTP agent token):

```bash
php bin/freesaas.php update check --format=json
php bin/freesaas.php update apply --target=all --format=json
```

Overlay never writes `cfg/`, `var/`, uploads. After apply: confirm new `modules/<id>` stay `=0` unless intentionally enabled (including keeping `demo=1` on the stand).

## Sample data (relative to today)

```bash
php bin/freesaas.php demo seed --locale=ru --reset --format=json
php bin/freesaas.php demo seed --locale=en --reset --format=json
```

Fills cabinets, catalog, clients, staff, calendar, ledger/finance, audio demo, rates/procents, etc. `--reset` clears prior demo domain rows (keeps `admin` / `demo` users and schema). Ensures `base_system` singleton via `SystemRepository::ensureRow` (needed after schema-only migrate / bootstrap that skipped the row — calendar work hours write there).

## Nightly refresh

Prefer **re-seed** (dates stay “today”) over a frozen mysqldump:

```bash
# copy bin/demo-restore.example.sh → host path, chmod +x
15 3 * * * /usr/local/bin/freesaas-demo-restore.sh demoru
20 3 * * * /usr/local/bin/freesaas-demo-restore.sh demoen
```

Does not touch `cfg/`. Do **not** use HTTP `/cron/tick` for this (keep `agent_token` empty).

## Suggested `[modules]` on demo

On for a product tour: `demo`, `core`, `comments`, `clients`, `catalog`, `calendar`, `workweeks`, `cabinets`, `procedures`, `orders`, `ledger`, `finance`, `funds`, `rates`, `procents`, `payments`, `vacations`, `stocks`, `stickers`, `units`, `suppliers`, `updates` (CLI apply), plus whatever audio/BI pack you show.

Force **off**: `llm`, `assistant`, `client_mcp`, `bots`, `terminals`, `asr`, `banks`, `yclients`, `clientics`, `sites` (or accept cart writes), `likespa` legacy APIs, `booking` if you do not want public books.

List **every** discovered module explicitly (`=0`/`=1`) so absent-key fail-closed never surprises you after a full zip apply.

## Two locales

Two deploys of the same release: e.g. `demoru` / `demoen` with `app.locale=ru|en` and separate DBs/seeds. UI chrome follows locale; DB `NAME`/UGC does not — use language-matched golden data.
