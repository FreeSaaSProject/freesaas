# Warehouses

Product module `stocks`: Enable for warehouse cards and leftovers. Soft cabinets/stickers/catalog/norms/calendar. Required by stickers. Use when inventory locations matter beyond a single implicit stock. Catalog maturity: `release`.

## Enable

```bash
php bin/freesaas.php module enable stocks --accept-risk --format=json
```

Or `[modules] stocks=1` in `cfg/config.ini`.

Permissions: `stocks.view`, `stocks.edit`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `cabinets`, `stickers`, `catalog`, `norms`, `calendar`

## Tables

| Table | Role |
|-------|------|
| `base_stocks` | Owned by `stocks` |

Columns: `NAME`, `DESCR`, `OPERATIVE` (default for receipts), `ID_CABINET`, `DT`.

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Default warehouse for receipts

`OPERATIVE=1` marks the default receipt stock. Resolution (`StocksRepository::defaultForStaff`):

1. Operative stock for the staff user’s `skin` cabinet.
2. Else the single site-wide operative stock.
3. Else agent/UI must pass `id_stock` explicitly.

`stickers.receipts.create` omits `id_stock` → uses that default. `stocks.list` JSON includes `default_stock_id`.

## Human UI

- `/stocks` warehouses; `/stocks/leftovers` leftovers report (rail: **Склад**).

| Path | Permission |
|------|------------|
| `/stocks` | `—` |
| `/stocks/leftovers` | `—` |

Min/max stock thresholds live on the **catalog product** card (`stock_min` / `stock_max`), not on the warehouse.

## Leftovers (`/stocks/leftovers`)

Successor of oper `show_stock_analyze`:

- Live qty from sticker ins
- `need_future` from upcoming calendar events × product `type_id` × `base_good_defaults` (QTYU2)
- Monthly in/out (`months`, default 6)
- Category filter `show_cat` + section headers
- Warn when qty &lt; min or qty ≤ need_future

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

| Op | Method | Path |
|----|--------|------|
| `stocks.list` | GET | `/stocks` |
| `stocks.get_edit` | GET | `/stocks/{id}/edit` |
| `stocks.create` | POST | `/stocks/new` |
| `stocks.update` | POST | `/stocks/{id}/edit` |
| `stocks.leftovers` | GET | `/stocks/leftovers` |

```bash
curl -sS -b jar.txt 'https://example/stocks?format=json'
curl -sS -b jar.txt 'https://example/stocks/leftovers?months=6&format=json'
```

## Notes

- Soft cabinets / stickers / catalog / norms / calendar.
- Legacy `TYPE` / virtual / autocontrol columns removed (migration `002_drop_legacy_stock_type_fields`).
