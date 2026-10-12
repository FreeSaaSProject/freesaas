# phpqrcode (vendored)

Merged single-file **PHP QR Code** library used by FreeSaaS sticker label QR
generation (`modules/stickers/lib/StickerQr.php`).

## Upstream source

| Field | Value |
|---|---|
| Project | PHP QR Code (phpqrcode) |
| Author | Dominik Dzienia |
| Homepage | http://phpqrcode.sourceforge.net/ |
| SourceForge | https://sourceforge.net/projects/phpqrcode/ |
| Version (in file) | **1.9.9** |
| Build stamp | **20130526** |
| File form | Merged `phpqrcode.php` (auto-generated convenience build: no external files, cache/logging disabled) |
| Based on | libqrencode (Kentaro Fukuchi), LGPL 2.1 |

Vendored path in FreeSaaS: `modules/stickers/lib/phpqrcode/phpqrcode.php`
(commit that introduced it: sticker QR scan/print flow).

## License

**LGPL-3.0-or-later** (headers in `phpqrcode.php`).

Full GNU texts shipped next to this README (do not strip from releases):

- [`LICENSE.LGPL-3.0.txt`](LICENSE.LGPL-3.0.txt) — GNU Lesser General Public License v3
- [`LICENSE.GPL-3.0.txt`](LICENSE.GPL-3.0.txt) — GNU General Public License v3  
  (LGPL-3.0 is additional permissions on top of GPL-3.0; both texts must be available)

LGPL does **not** forbid commercial builds of a host application, but **LGPL
obligations for this library remain** (preserve notices, keep the library
replaceable / convey corresponding source for the LGPL parts as required by
LGPL). A FreeSaaS commercial license does not re-license phpqrcode; see root
[`THIRD_PARTY.md`](../../../../THIRD_PARTY.md) and
[`COMMERCIAL-LICENSE.md`](../../../../COMMERCIAL-LICENSE.md).
