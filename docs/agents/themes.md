# Storefront themes

How FreeSaaS skins work under `themes/<id>/`. Independent of any legacy CMS: port whatever public site you have (static HTML, WordPress theme, custom PHP) into this contract.

## Mental model

| Piece | Role |
|-------|------|
| `themes/<id>/` | One visual skin: PHP layout/views + static assets |
| `base_site_hosts.theme` | Which skin the current `HTTP_HOST` uses |
| `SiteTheme` | Resolves id → paths; `render` / `partial` / `assetUrl` |

Do **not** invent a second template engine. Views are plain PHP with `e()` / `safe_html()` / `te()`.

## Layout on disk

```
themes/<id>/
  theme.json          # id, name, version; optional source note
  layouts/layout.php  # default chrome; wraps $content
  layouts/*.php       # optional named layouts (e.g. promo full-bleed)
  partials/           # header, footer, …
  views/              # required names below
  promo_modules/      # optional LP block templates (CODE.php)
  assets/             # css, js, images, fonts, plugins
```

Public URLs: `/themes/<id>/assets/...` (`.htaccess`). PHP under `layouts|views|partials|promo_modules` is not served as static files.

## Required views

`SiteTheme::render('<name>', $data [, $layout])`:

| View | Typical data |
|------|----------------|
| `home` | `title`, `lead`, `html`, `show_shop`, `site`, `nav` |
| `page` | `page`, `html`, `title`, `meta_title`, `meta_description`, `site`, `nav`, `breadcrumbs`, `children` |
| `shop` | `title`, `items[]`, `categories[]`, `flash`, `ret`, `site`, `nav` |
| `product` | `product`, `categories[]`, `site`, `nav` |
| `cart` | `lines[]`, `total`, `flash`, `orders_ok`, `booking_ok`, `booking_product_ids[]`, `booking_date`, `booking_meta`, `site`, `nav` |
| `promo` | `promo`, `html` (rendered modules), `section_nav`, `site`, `nav` |
| `error` | `title`, `message`, `hint`, `site`, `nav` |

Layout receives `$content` plus page vars. Prefer `SiteTheme::partial(...)`. Missing view → `modules/sites/views/` if present; missing layout → `_fallback_layout.php`.

Render article bodies with `page_html($html)`. A theme may set `"rich_html": true` in `theme.json`: articles then keep `class` / `id`, `section`, `article`, `figure`, tables, and internal links without `nofollow` (staff-authored marketing pages). Scripts, `style`, `on*` and unsafe URLs are stripped either way. Default `false` — existing skins render as before.

Use `I18n::locale()` for `<html lang>`; with a host `locale` set the storefront forces that language, so theme chrome via `t()` follows the domain.

## Port checklist (any legacy site)

1. Inventory chrome (header/footer), CSS/JS actually used on public pages, fonts, logo.
2. Create `themes/<id>/` + `theme.json`.
3. Copy static assets; keep CSS `url(../fonts|images/…)` working by mirroring folders under `assets/`.
4. Rewrite chrome to FreeSaaS data: `$site['name'|'tel']`, `$nav`, `SiteTheme::assetUrl(...)`. Escape with `e()` / `safe_html()`.
5. Implement every required view (start from `themes/default/`). Keep shop/cart form field names unchanged.
6. If fonts use `ttf|otf|eot`, allow them in `.htaccess` and `cfg/htaccess.storefront.example`.
7. Bind host: **Site → Hosts** → `theme=<id>` (ini `sites.theme` is fallback only).
8. If admin and storefront are separate trees, rsync code (exclude live `cfg/*.ini`) and keep local config.
9. Smoke: health `sites.storefront`, `/`, `/shop`, `/cart`, asset URL 200, one promo if used.

## Promo LP modules (optional)

Admin stores a `MODULES` blob (`base_promopages`). Storefront renders via `PromopageRenderer` → `themes/<id>/promo_modules/<CODE>.php` (vars: `$moduleCode`, `$moduleData`, `$promo`). Missing file → skip that block. See storefront runbook for host `promo_id` / `/promo/{code}`.

## Licensed / instance skins

`themes/default` is the only skin in the public FreeSaaS tree. Branding skins that reuse third-party template code live in **private** repos and stay gitignored locally:

| Theme id | Private repo |
|----------|----------------|
| `likespa` | https://github.com/ilcommm/freesaas-theme-likespa |
| `balance` | https://github.com/ilcommm/freesaas-theme-balance |

Clone or sync into `themes/<id>/` on the instance (or storefront tree), or let `/updates` pull them: add `[theme.<id>]` (repo, token, channel, optional `path_prefix`) to `cfg/updates.ini` — see [`update.md`](update.md). Bump `version` in `theme.json` to publish a theme update. Do not commit them to `FreeSaaSProject/freesaas`.

## Do / don’t

- One theme id = one folder; switch via host row.
- Presentation only in themes — no product SQL/routes.
- Don’t commit secrets or licensed skins to the public package; don’t expect day-one parity of every legacy widget.

## Related

- [`sites.md`](sites.md) — storefront runtime, hosts, cart
- [`../../AGENTS.md`](../../AGENTS.md) — platform hard rules
