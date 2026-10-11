# LikeSpa instance pack

Product module `likespa`: restores, FREE_TICKET, flyer/promo, term scans, calendar overlays. Soft-depends on `sites` / `booking` / `procedures` / … — does not own the storefront kernel ([`sites.md`](sites.md)).

## Cutover from legacy likespa2 / PHPShop / Balance

1. Enable `sites` (+ catalog/orders/clients) on the shared FreeSaaS DB; enable `likespa` when you need instance features.
2. Run sites migrations `006`/`007` — seeds default host from `public_host` / `mothersite`.
3. Port the public skin into `themes/<id>/` ([`themes.md`](themes.md)); set `base_site_hosts.theme` (often `likespa`).
4. Add a second host row (e.g. balance) with its promo + catalog folder (legacy Balance used category **16**).
5. Point public vhosts at `site.php` (or rsync to a storefront-only tree like `likespa2.hm`). Keep old trees until parity.
6. Retire old PHPShop trees; do not keep writing `phpshop_*` from the new runtime.

## Procedure restores

Port of oper `show_procedure_restores` / `edit_procedure_restore`. Schema `base_procedure_restores` stays owned by `procedures`. UI + автовзаиморасчёт live in likespa.

### Enable

- `[modules] likespa=1` and `procedures=1` (soft: `payments=1` for settlement).
- Ini: `cfg/modules/likespa.ini` — `restores_enabled=1`, `restores_lookback_days=4`, `restores_payment_type=1`.
- Permission: `likespa.restores`. Bulk delete / act signature: `SUPERADMIN_ACCESS`.

### Human UI

- `/likespa/restores` — list (menu under procedures group)
- `/likespa/restores/new` — create (cancelled procs: `ENABLED<1`, `SUMM=0`, `SUMM_DEFAULT>0`; window = today, or today−N days for superadmin)
- `/likespa/restores/{id}/edit` — edit reason/summ/sign

On save:

1. Set `ID_OPERATOR_PAID` (operator, or admin if `NOT_IN_TEAM`)
2. If payments on and no row with `DESCR = procedureId` → create staff debit `DIRECTION=-1`, **`ACCOUNT=0`** (internal settlement, no DPS)
3. `ProceduresRepository::applyRestore` → `RESTORED=1`, `SUMM=SUMM_DEFAULT`, `ENABLED=1`

### Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/likespa/restores?format=json'
curl -sS -b jar.txt 'https://example/likespa/restores/new?format=json'
curl -sS -b jar.txt -X POST 'https://example/likespa/restores/new?format=json' \
  -d 'reason=test&summ=500&id_procedure=123'
curl -sS -b jar.txt 'https://example/likespa/restores/1/edit?format=json'
```

## Settings / FREE_TICKET

LikeSpa **product** feature (not part of sites/booking core). Overlay hooks: `sites.cart.booking`, `sites.diagnose`, `sites.host_edit.sections` / `.save`, `booking.book.before`. Health check: `likespa.free_ticket`.

Superadmin: `/likespa/settings` (`likespa.settings.get` / `.save`).

| Field | Effect |
|-------|--------|
| `free_ticket_threshold` | `base_system.FREE_TICKET`; `0` = off storefront-wide |
| flyer / promo fields | Flyer + promoter discount blob |

Per-domain enable: POST `free_ticket=1` on host save (likespa injects the checkbox on `/sites/hosts/{id}/edit`). Cart eligibility: sum ≥ threshold, not today, host flag on.

```bash
curl -sS -b jar.txt 'https://admin.example/likespa/settings?format=json'
curl -sS -b jar.txt -X POST 'https://admin.example/likespa/settings?format=json' \
  -d 'free_ticket_threshold=1900'
# Overlay appears in sites diagnose when likespa is booted:
curl -sS -b jar.txt 'https://admin.example/sites/status?format=json'   # → free_ticket{…}, issues with module=likespa
php bin/freesaas.php health --module=likespa --format=json
```

### Notes (restores)

- No auto-DPS / ledger moves for these payments.
- Deleting a restore row does not reverse the payment or procedure flags.
