# Third-party components

Inventory of third-party / vendored material in this repository and how it
relates to FreeSaaS dual licensing (AGPL-3.0-or-later + optional commercial
license). **Nothing in this file authorizes removal or replacement** of listed
components; it is documentation only.

FreeSaaS first-party code is Copyright © 2026 Ilya Kleshnin
([`LICENSE`](LICENSE)). Third-party components remain under their own terms even
when FreeSaaS is used under a commercial license
([`COMMERCIAL-LICENSE.md`](COMMERCIAL-LICENSE.md)).

Audio-family notes also live in [`modules/audio/THIRD_PARTY.md`](modules/audio/THIRD_PARTY.md).

There is no Composer or npm lockfile; dependencies are either vendored in-tree
or loaded at runtime from CDNs.

## Blockers / high risk

These may interfere with AGPL redistribution or with shipping a dual-licensed
product that includes them. They were **not** removed as part of dual-licensing
scaffolding.

| Component | Location | License (as found) | Notes |
|---|---|---|---|
| BeautyZone / DexignLab-style theme CSS/JS | `themes/likespa/assets/css/style.css`, `templete.css`, `skin/skin-2.css`, `themes/likespa/assets/js/custom.js` (`BeautyZone`, `dlab-*`; `theme.json` source `likespa.hm/tpl/beauty`) | No license file in tree; ThemeForest / Envato-style commercial template | **High risk** for AGPL redistrib of the likespa theme. Envato Regular License typically forbids redistributing the template as OSS. `custom.js` is currently unused by layout but still in git. |
| Theme stock images | `themes/likespa/assets/images/**` | No licenses | Treat as template-bundled / unclear until proven otherwise. |

## Needs review

| Component | Location | License (as found) | Notes |
|---|---|---|---|
| LinguaStemRu | `modules/audio_pipeline/lib/LinguaStemRu.php` | Unclear — port of oper `libs/stem/LinguaStemRu.php`; no upstream LICENSE in tree | Clarify provenance before relying on AGPL or commercial redistrib of this file. |
| Themify Icons | `themes/likespa/assets/css/themify-icons.css`, `themes/likespa/assets/fonts/themify/*` | No LICENSE in repo; IcoMoon metadata; upstream often SIL OFL (font) + MIT (CSS) | Document upstream license if kept. |
| Fontello / fa-viber | `themes/likespa/assets/fonts/fontello/*`, `themes/likespa/assets/fonts/fa-viber/*`, `themes/likespa/assets/css/fa-viber.css` | Fontello: “Copyright (C) 2020 by original authors”; per-glyph licenses not recorded | Unclear pack; trademark risk for Viber-branded glyph. |
| Font Awesome Free 5 orphans | `themes/likespa/assets/fonts/fontawesome/fa-{solid,regular,brands}-*` | Icons CC BY 4.0, Fonts SIL OFL 1.1, Code MIT (SVG metadata) | Appears unused (no FA5 CSS refs found) but still redistributed — attribution if kept. |
| Owl Carousel 2.2.1 | `themes/likespa/assets/plugins/owl-carousel/*` | Upstream MIT; minified header has blank `Licensed under ()` | Treat as MIT; verify against upstream if needed. |

## Compatible / lower risk

| Component | Location | License (as found) | Notes |
|---|---|---|---|
| phpMorphy + morphology dicts | `modules/audio_pipeline/vendor/phpmorphy` | **LGPL-2.1+** (`LICENSE.LGPL-2.1.txt`, vendor README) | Compatible with AGPL if notices kept. For commercial dual-license of FreeSaaS, keep as a separate LGPL library (do not strip LGPL rights). |
| jQuery 3.3.1 | `themes/likespa/assets/js/jquery.min.js` | MIT | OK |
| Bootstrap 4.1.0 | `themes/likespa/assets/css/bootstrap.min.css`, `themes/likespa/assets/plugins/bootstrap/js/bootstrap.min.js` | MIT | OK; JS expects Popper (not shipped as a separate file). |
| Font Awesome 4.7.0 | `themes/likespa/assets/css/font-awesome.min.css`, `fonts/fontawesome/fontawesome-webfont.*`, `FontAwesome.otf` | Font SIL OFL 1.1, CSS MIT | Keep OFL/MIT notices. Brand glyphs have trademark limits. |
| Flaticon icon pack | `themes/likespa/assets/css/flaticon.css`, `themes/likespa/assets/fonts/flaticon/*` | **CC BY 3.0** (attribution in `flaticon.html`: Freepik, surang) | Not GPL-incompatible; attribution must travel with redistributions. |
| Chart.js 4.4.1 | CDN in `modules/finance/views/balance.php`, `modules/wellness/views/calendar.php` | MIT | Runtime CDN; not vendored in git. |
| Google Fonts (DM Sans, Syne) | CDN in `modules/core/views/layout.php`, `layout_auth.php` | Typically OFL (font authors) | Runtime CDN. |

## Intentionally not shipped

| Component | Notes |
|---|---|
| getID3 | GPL-2+ / commercial — documented in `modules/audio/THIRD_PARTY.md` as not vendored. Prefer not to add under AGPL dual-licensing without a clear decision. |
| audio.js / SWF | Obsolete; use native HTML5 `<audio>`. |

## First-party (covered by project LICENSE)

| Location | Notes |
|---|---|
| `app/`, `bin/`, `modules/*/lib|pages|views|assets` (except vendor) | FreeSaaS first-party |
| `themes/default/` | First-party storefront theme |
| `themes/likespa` overlays such as `freesaas.css`, `cart.css`, `cart.js`, `cart-booking.js`, `phone-ru.js`, `site.js` | Project overlays on top of third-party theme assets |
| `assets/css/core.css`, `assets/js/core.js` | Admin chrome |

## Summary

- No GPL-only libraries are currently vendored in-tree.
- The main dual-licensing / AGPL redistrib concern is the **likespa BeautyZone / DexignLab-style theme** (CSS/JS/images), not the PHP core.
- phpMorphy (LGPL-2.1+) and Flaticon (CC BY) add compliance obligations but are manageable if notices and attribution are preserved.
