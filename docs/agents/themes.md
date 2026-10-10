# Storefront themes

How FreeSaaS skins work under `themes/<id>/`. Independent of any legacy CMS: port whatever public site you have (static HTML, WordPress theme, PHPShop, custom PHP) into this contract.

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
| `page` | `page`, `html`, `title`, `site`, `nav` |
| `shop` | `title`, `items[]`, `categories[]`, `flash`, `ret`, `site`, `nav` |
| `product` | `product`, `categories[]`, `site`, `nav` |
| `cart` | `lines[]`, `total`, `flash`, `orders_ok`, `booking_ok`, `booking_product_ids[]`, `booking_date`, `booking_meta`, `site`, `nav` |
| `promo` | `promo`, `html` (rendered modules), `section_nav`, `site`, `nav` |
| `error` | `title`, `message`, `hint`, `site`, `nav` |

Layout receives `$content` plus page vars. Prefer `SiteTheme::partial(...)`. Missing view → `modules/sites/views/` if present; missing layout → `_fallback_layout.php`.

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

## Do / don’t

- One theme id = one folder; switch via host row.
- Presentation only in themes — no product SQL/routes.
- Don’t commit secrets; don’t expect day-one parity of every legacy widget.

## Related

- [`sites.md`](sites.md) — storefront runtime, hosts, cart
- [`../../AGENTS.md`](../../AGENTS.md) — platform hard rules
