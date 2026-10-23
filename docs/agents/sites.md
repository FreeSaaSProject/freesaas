# Sites storefront (multi-host)

Public storefront runtime in the **same FreeSaaS package** as the admin UI. Shared MySQL `base_*`; not a second CMS. Several public domains share one DB via `base_site_hosts`.

## Roles

| Host | Entrypoint | Role |
|------|------------|------|
| Admin (`admin.example.com`) | `index.php` | `admin` (default) |
| Public (`www.example.com`, `balance.example.com`, …) | `site.php` | `storefront` (`FREESAAS_ROLE`) |

Same code tree preferred (one `cfg/`, one `[connect]`). Two copies of the same release also work if each `config.ini` points at the **same** database.

## Host binding (`base_site_hosts`)

Each row:

| Field | Effect |
|-------|--------|
| `host` | Match `HTTP_HOST` (no scheme) |
| `theme` | `themes/<id>/` |
| `promo_id` | Home `/` content + META |
| `catalog_root_ids` | Shop folders + descendants (JSON list; empty = entire catalog). `catalog_root_id` kept as first id for BC. |
| `tel` / `name` | Storefront phone / brand (fallback: `[sites]` `tel` / `seo_name`) |
| `is_default` | Used when Host is unknown |

Articles (`base_page_categories.site_host_id`) and redirects (`base_redirects.site_host_id`): `0` = all hosts; otherwise only that host. Migration assigns existing rows to the default host.

Terminals keep product allowlists via `base_terminals.GOODS` — not tied to hosts.

## Enable

```bash
php bin/freesaas.php module enable sites --accept-risk --format=json
php bin/freesaas.php migrate run --module=sites --format=json
# Soft deps for shop/checkout/CMS content:
php bin/freesaas.php module enable catalog --format=json
php bin/freesaas.php module enable orders --format=json
php bin/freesaas.php module enable clients --format=json
# Optional: appointment wizard on /cart (same slot engine as staff booking)
php bin/freesaas.php module enable booking --accept-risk --format=json
# Slot deps must also be enabled (storefront boots them via runtime=both):
# workweeks, calendar, procedures, rates
```

Admin UI (group **Site**): **Hosts**, SEO fallback, promo pages, articles, redirects, storefront settings (`admin_host`).

Ini `sites.theme` / `app.public_host` remain seed/fallback only; after migrate, edit hosts in UI.

## Public vhost

**Option A — SetEnv (same `.htaccess`):**

```apache
# In the public vhost:
SetEnv FREESAAS_FRONT site
DocumentRoot /path/to/freesaas
```

**Option B — storefront htaccess:** copy [`cfg/htaccess.storefront.example`](../../cfg/htaccess.storefront.example) over `.htaccess` on a storefront-only copy, or point `DirectoryIndex` / rewrite at `site.php`.

Point **multiple** public vhosts at the same tree; Host header selects the row.

Smoke:

```bash
curl -sS "https://www.example.com/"
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://www.example.com/agent/health"
# meta.sites.storefront → theme, public_host, host_id, role=storefront
```

## Routes (storefront)

| Path | Purpose |
|------|---------|
| `/` | Home = host promo (or generic home) |
| `/page/{…}/` | CMS page tree (`/page/vacancies/masseur/`); `link_rw` rewrites like `/contacts/` still work. **Not** `/p/` (reserved for polls/SMS) |
| `/shop`, `/shop/c/{id}`, `/shop/{id}` | Catalog under host `catalog_root_ids` (union) |
| `/cart`, `GET /cart/availability`, `POST /cart/checkout` | Session cart; **phone first** (`SitesGuest`) then, with `booking` on + bookable services → slot wizard → `BookingBook::createOrder`; else → `OrdersRepository::create`. Clients with status `block_online` cannot proceed (message: book at the centre). |
| `/promo/{code}` | Promo LP by code |
| `/sitemap.xml`, `/robots.txt` | Current host |
| `/.well-known/ai-catalog.json` | Domain AI Catalog for agent discovery; entries from EventBus `sites.ai_catalog` (`identifier`, `type`, `url` or `data`); 404 when no module contributes (e.g. `client_mcp` adds its server card per host) |
| `/llms.txt` | Site card for LLM readers ([llmstxt.org](https://llmstxt.org)): host name, summary, contacts, shop/cart links; modules append sections via EventBus `sites.llms_txt` (`sections[] = {title, lines[]}`) |

**Theme-independent chrome:** `SiteTheme::render` emits `sites.page.inject` (`view`, `layout`, `head`, `body_end`) and inserts the returned trusted HTML before `</head>` / `</body>` of any theme, including private ones. Sites itself adds `<link rel="alternate" type="text/markdown" href="/llms.txt">`. Escape everything you inject.
| unknown path | Redirects (scoped) then page-by-link then 404 |
| `/login` | Redirect to `admin_host` when set |

## Themes

Folder `themes/<id>/` with `theme.json`, `layouts/`, `views/`, `partials/`, `assets/`. Per-host via `base_site_hosts.theme`.

Shipped skins: `default` (minimal), `likespa` (instance skin). Theme contract / port guide: [`themes.md`](themes.md).

Promo LPs (`base_promopages`): host `promo_id` → `/`; also `/promo/{code}`. Modules blob rendered by `PromopageRenderer` via `themes/<id>/promo_modules/<CODE>.php`.

## Module runtime

`module.json` → `"runtime": "admin"|"storefront"|"both"`. Missing key = `admin` (not booted on `site.php`). Storefront boots `core`, `sites`, and modules marked `both` (e.g. catalog, orders, clients).

Legacy storefront cutover: [`likespa-restores.md`](likespa-restores.md)#cutover-from-legacy-storefront.

## Reviews (Yandex + 2GIS widgets)

No separate product module. Add a root CMS article (Sites → Pages) with `linkRW=/reviews/` and paste official iframes into `content`:

- Yandex iframe: `https://yandex.ru/maps-reviews-widget/{orgId}?comments` (org id from the Maps card URL `/org/…/{id}/`)
- 2GIS rating badge: prefer `<img>` from `https://widget.2gis.ru/api/content/?org_id=…&branch_id=…&…` linking to the firm reviews tab (firm id from `2gis.ru/…/firm/{id}`). Optional iframe host `widget.2gis.ru` is also allowlisted.

Storefront themes render article body via `page_html()` (not bare `safe_html()`): Yandex/2GIS widget iframes are kept; scripts and other embeds stay stripped.

## Health

Check id `sites.storefront` (theme present, hosts table / fallback host). Meta includes `booking_active`, `diagnose` → `/sites/status?format=json`.

```bash
php bin/freesaas.php health --module=sites --format=json
```

## Agent API (session JSON)

Convention: [`module-agent-api.md`](module-agent-api.md). Ops live in `modules/sites/module.json` → `agent.ops`. Same routes as the admin UI; respond when `?format=json` or `Accept: application/json`. Hosts / settings / status / SEO require session + `SUPERADMIN_ACCESS` (and the listed `sites.*` permission).

### Diagnose first

```bash
# Admin host, after login (session cookie):
curl -sS -b jar.txt 'https://admin.example.com/sites/status?format=json'
```

`sites.status` payload (high level):

| Field | Meaning |
|-------|---------|
| `status` | `ok` \| `degraded` \| `fail` |
| `modules` | Soft deps: `catalog`, `orders`, `clients`, `booking`, slot stack (`workweeks`/`calendar`/`procedures`/`rates`) |
| `hosts[]` | Per domain: `theme`/`theme_ok`, `catalog_root_ids[]`, `promo_id`, `enabled` |
| `booking.active` | Storefront wizard can use the slot engine |
| `booking.bookable_services` | Count from `BookingCatalog` (`-1` = error) |
| `issues[]` | `{level,code,message[,module]}` — start here when cart/slots misbehave |

Product modules may append keys / issues via EventBus `sites.diagnose` (do not treat overlay fields as sites core).

UI twin: **Sites → Diagnostics** (`/sites/status`).

### Ops catalog

| Op | Method / path | Purpose |
|----|---------------|---------|
| `sites.status` | `GET /sites/status` | Full storefront diagnose |
| `sites.hosts.list` | `GET /sites/hosts` | List hosts; filters `q`, `enabled` |
| `sites.hosts.get` | `GET /sites/hosts/{id}/edit` | One host + theme/promo/catalog options (`/sites/hosts/new` for blank) |
| `sites.hosts.create` | `POST /sites/hosts/new` | Create host |
| `sites.hosts.update` | `POST /sites/hosts/{id}/edit` | Update host |
| `sites.settings.get` / `.save` | `/sites/settings` | `admin_host` |
| `sites.seo.get` / `.save` | `/sites/seo` | Fallback name/title/keywords/tel in `cfg/modules/sites.ini` (`seo_name`, `seo_title`, `seo_keywords`, `tel`) |
| `sites.promopages.list` | `GET /sites/promopages` | Promo LPs |
| `sites.pages.list` | `GET /sites/pages` | CMS articles; `site_host_id` |
| `sites.redirects.list` | `GET /sites/redirects` | Redirects; `site_host_id` |
| `sites.cart.availability` | `GET /cart/availability` | **Public** slots — call with the **public** `Host` header / vhost |

Host write fields (POST body / form): `host`, `name`, `theme`, `promo_id`, `catalog_root_ids[]` (multi; empty = entire catalog), `tel`, `enabled`, `is_default`, `num`. Extra host fields come from EventBus `sites.host_edit.sections` / `.save`.

Cart booking meta for themes: EventBus `sites.cart.booking` (sites emits; product modules fill optional keys).

### Storefront booking smoke

```bash
# Open days with slots (public Host):
curl -sS 'https://www.example.com/cart/availability?mode=dates&product_ids=13'
# Day grid:
curl -sS 'https://www.example.com/cart/availability?mode=day&date=2026-10-12&product_ids=13'
# Masters ahead:
curl -sS 'https://www.example.com/cart/availability?mode=masters&product_ids=13'
```

Modes: `day` (default), `masters`, `dates`. Empty past day → `empty_reason=past`. Staff twin (session): [`booking.md`](booking.md) → `/booking/availability`, `POST /booking/book`.

### Related

- [`booking.md`](booking.md) — slot engine + cart wizard
- [`themes.md`](themes.md) — theme port / bind
- [`debugging.md`](debugging.md) — health / blocked modules
- Platform: `php bin/freesaas.php health` → check `sites.storefront`
