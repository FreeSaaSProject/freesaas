# Stickers QR (`/qr2.php`)

Legacy-compatible warehouse sticker scanner for FreeSaaS. Printed labels encode:

`https://{public_host}/qr2.php?q={HASH}`

HASH = `md5(PIN + time[+n])` (same as oper / `StickerInsRepository::makeHash`).

## Auth (no `qq` / `qrauth`)

| Caller | Result |
|--------|--------|
| Guest / no session | 302 → storefront `/` (HTML) or JSON 403 |
| Staff session + `GOOD_USE` (or SUPERADMIN) | Scanner UI / JSON |
| Activate / revision / movement | also needs user `STOCK_REVISION` (or SUPERADMIN) |

Staff must be logged into FreeSaaS **on the host that serves the QR** (usually `public_host`). If `admin_host` ≠ `public_host`, set shared cookie domain (UI: **Stickers → Settings** `/stickers/settings`, or ini):

```ini
[app]
session_cookie_domain=.example.com
```

Then **re-login** (old host-only cookie is not upgraded). Diagnose checklist: `/stickers/status` (`setup[]` + `issues[].fix`).

## Security

- Route is `public` **without** `permission` / `capability` (gate in handler).
- Allow-listed in `bin/check-routes.php`.
- **GET** = inspect only. Mutations are **POST + CSRF** (not CSRF-exempt).
- Legacy GET mutate links show a confirm POST form.
- One-shot `token` on ins/outs (replay → re-scan).

## Agent ops

Session JSON (`?format=json` / `Accept: application/json`). See `modules/stickers/module.json` → `agent.ops`:

| id | method | path |
|----|--------|------|
| `stickers.qr.diagnose` | GET | `/stickers/status` |
| `stickers.settings.get` / `.update` | GET/POST | `/stickers/settings` (SUPERADMIN) |
| `stickers.qr.scan` | GET | `/qr2.php?q=` |
| `stickers.qr.act` | POST | `/qr2.php` (+ csrf) |
| `stickers.print.preview` | GET | `/stickers/ins/print` |
| `stickers.print.run` | POST | `/stickers/ins/print` |

## Ins list filters / bulk

`/stickers/ins` compact filters (oper parity): year, month, material name (`good_q` → `is_material`), stock, supplier, act/good id, activated, revision, QQR piece/multi, weighable, used.

Bulk (selection): print, send to reactivation (`DT_REPRINT`), delete.

Stock-wide (filter `id_stock` required): revision on/off (off = SUPERADMIN), movement on/off — same rules as oper (no open weight outs; mutually exclusive modes).

## Print (A4 group)

Staff:

- Menu **Print stickers** → `/stickers/ins/print` (all pending; multi-page).
- Bulk action **Print selected** on `/stickers/ins` → `/stickers/ins/print?ids=…`.
- Grid **5×13 = 65** labels per A4 page (oper parity); CSS page breaks for more.
- Does **not** set `DT_PRINT` (activation is on QR scan). QR via LGPL phpqrcode.

Optional anonymous warehouse-PC link (oper `printstickers=1`):

```ini
; cfg/modules/stickers.ini — off by default
[stickers]
public_print_enabled=1
```

Or toggle on `/stickers/settings` (SUPERADMIN). URL: `https://{admin_host}/stickers/printstickers`.

| Rule | Behavior |
|------|----------|
| Off | HTTP 404 plain text |
| On | GET-only A4 sheet of pending labels |
| Denied | POST, `?ids=`, JSON/`format=json`, any mutate |

Copy example: `modules/stickers/config.example.ini`.

## Manual check

1. Logged-out phone scan → company home.
2. Staff with remember-me + `GOOD_USE` → scanner.
3. Activate / amount write-off / weight open-close / cancel.
4. Revision + movement with `STOCK_REVISION`.
5. Print sheet URLs match mothersite `/qr2.php?q=…`.
6. With `public_print_enabled=0` → `/stickers/printstickers` is 404; with `=1` → sheet only.
7. `php bin/freesaas.php module validate stickers` and `php bin/check-routes.php`.
