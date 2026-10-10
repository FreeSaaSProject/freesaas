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
| `catalog_root_id` | Shop folder + descendants (`0` = entire catalog) |
| `tel` / `name` | Storefront phone / brand (fallback: `base_system`) |
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
| `/shop`, `/shop/c/{id}`, `/shop/{id}` | Catalog under host `catalog_root_id` |
| `/cart`, `GET /cart/availability`, `POST /cart/checkout` | Session cart; with `booking` on + bookable services → slot wizard → `BookingBook::createOrder`; else → `OrdersRepository::create` |
| `/promo/{code}` | Promo LP by code |
| `/sitemap.xml`, `/robots.txt` | Current host |
| unknown path | Redirects (scoped) then page-by-link then 404 |
| `/login` | Redirect to `admin_host` when set |

## Themes

Folder `themes/<id>/` with `theme.json`, `layouts/`, `views/`, `partials/`, `assets/`. Per-host via `base_site_hosts.theme`.

Shipped skins: `default` (minimal), `likespa` (instance skin). Theme contract / port guide: [`themes.md`](themes.md).

Promo LPs (`base_promopages`): host `promo_id` → `/`; also `/promo/{code}`. Modules blob rendered by `PromopageRenderer` via `themes/<id>/promo_modules/<CODE>.php`.

## Module runtime

`module.json` → `"runtime": "admin"|"storefront"|"both"`. Missing key = `admin` (not booted on `site.php`). Storefront boots `core`, `sites`, and modules marked `both` (catalog, orders, clients, likespa).

## Cutover from legacy likespa2 / PHPShop / Balance

1. Enable `sites` (+ catalog/orders/clients) on the shared FreeSaaS DB.
2. Run migrations `006`/`007` — seeds default host from `public_host` / `mothersite`.
3. Port the public skin into `themes/<id>/` ([`themes.md`](themes.md)); set `base_site_hosts.theme`.
4. Add a second host row (e.g. balance) with its promo + catalog folder (legacy Balance used category **16**).
5. Point public vhosts at `site.php` (or rsync to a storefront-only tree like `likespa2.hm`). Keep old trees until parity.
6. Retire old PHPShop trees; do not keep writing `phpshop_*` from the new runtime.

## Reviews (Yandex + 2GIS widgets)

No separate product module. Add a root CMS article (Sites → Pages) with `linkRW=/reviews/` and paste official iframes into `content`:

- Yandex iframe: `https://yandex.ru/maps-reviews-widget/{orgId}?comments` (org id from the Maps card URL `/org/…/{id}/`)
- 2GIS rating badge: prefer `<img>` from `https://widget.2gis.ru/api/content/?org_id=…&branch_id=…&…` linking to the firm reviews tab (firm id from `2gis.ru/…/firm/{id}`). Optional iframe host `widget.2gis.ru` is also allowlisted.

Storefront themes render article body via `page_html()` (not bare `safe_html()`): Yandex/2GIS widget iframes are kept; scripts and other embeds stay stripped. Seed migrator: `fill_reviews_page_widgets` (LikeSpa ids prefilled; edit the article to change orgs).

```bash
php bin/data-migrate.php --module=sites --id=fill_reviews_page_widgets
```

## Health

Check id `sites.storefront` (theme present, hosts table / fallback host).
