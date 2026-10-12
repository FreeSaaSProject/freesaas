# Third-party components

Inventory of third-party / vendored material in the **public FreeSaaS**
distribution and how it relates to dual licensing (AGPL-3.0-or-later + optional
commercial license). **Nothing in this file authorizes removal or replacement**
of listed components; it is documentation only.

FreeSaaS first-party code is Copyright © 2026 Ilya Kleshnin
([`LICENSE`](LICENSE)). Third-party components remain under their own terms even
when FreeSaaS is used under a commercial license
([`COMMERCIAL-LICENSE.md`](COMMERCIAL-LICENSE.md)).

Audio-family notes also live in [`modules/audio/THIRD_PARTY.md`](modules/audio/THIRD_PARTY.md).

There is no Composer or npm lockfile; dependencies are either vendored in-tree
or loaded at runtime from CDNs.

## Out of scope (not part of public FreeSaaS)

Instance-specific storefront themes and branding are **not** part of the public
FreeSaaS distribution. The LikeSpa pack lives in the private GitHub repository
[`ilcommm/freesaas-theme-likespa`](https://github.com/ilcommm/freesaas-theme-likespa)
and is deployed onto hosts as `themes/likespa/` (gitignored here). Do not treat
such themes as AGPL-covered FreeSaaS assets.

The public tree ships [`themes/default/`](themes/default/) as the reference
first-party storefront theme.

## Compatible / lower risk

| Component | Location | License (as found) | Notes |
|---|---|---|---|
| phpMorphy + morphology dicts | `modules/audio_pipeline/vendor/phpmorphy` | **LGPL-2.1+** (`LICENSE.LGPL-2.1.txt`, vendor README) | Sole lemmatizer for audio stem/reparse. Compatible with AGPL if notices kept. For commercial dual-license of FreeSaaS, keep as a separate LGPL library (do not strip LGPL rights). |
| PHP QR Code (phpqrcode) | `modules/stickers/lib/phpqrcode` | **LGPL-3.0-or-later** (`LICENSE.LGPL-3.0.txt` + `LICENSE.GPL-3.0.txt`; vendor README) | Merged build **1.9.9 / 20130526** from http://phpqrcode.sourceforge.net/ (Dominik Dzienia; based on libqrencode LGPL 2.1). Used for sticker label QR. LGPL does not forbid commercial host builds, but library obligations remain (notices, replaceability / corresponding source for the LGPL library). Do not strip license texts. |
| Chart.js 4.4.1 | CDN in `modules/finance/views/balance.php`, `modules/wellness/views/calendar.php` | MIT | Runtime CDN; not vendored in git. |
| Google Fonts (DM Sans, Syne) | CDN in `modules/core/views/layout.php`, `layout_auth.php` | Typically OFL (font authors) | Runtime CDN. |

## Intentionally not shipped

| Component | Notes |
|---|---|
| getID3 | GPL-2+ / commercial — documented in `modules/audio/THIRD_PARTY.md` as not vendored. Prefer not to add under AGPL dual-licensing without a clear decision. |
| audio.js / SWF | Obsolete; use native HTML5 `<audio>`. |
| LinguaStemRu | Former Porter/Snowball RU stemmer port (unclear provenance); removed — lemmatization is phpMorphy-only. |

## First-party (covered by project LICENSE)

| Location | Notes |
|---|---|
| `app/`, `bin/`, `modules/*/lib|pages|views|assets` (except vendor) | FreeSaaS first-party |
| `themes/default/` | First-party storefront theme |
| `assets/css/core.css`, `assets/js/core.js` | Admin chrome |

## Summary

- No GPL-only libraries are currently vendored in the public tree.
- Private / instance themes are out of scope for this file.
- No outstanding public review items for unclear-provenance code.
- phpMorphy (LGPL-2.1+) and phpqrcode (LGPL-3.0-or-later) stay manageable if
  notices and full GNU license texts are preserved.
