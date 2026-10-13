# Warehouses

Product module `stocks`: Enable for warehouse cards and leftovers. Soft cabinets/stickers/catalog/norms. Required by stickers. Use when inventory locations matter beyond a single implicit stock. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable stocks --accept-risk --format=json
```

Or `[modules] stocks=1` in `cfg/config.ini`.

Permissions: `stocks.view`, `stocks.edit`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `cabinets` >=0.1.0, `stickers` >=0.1.0, `catalog` >=0.1.0, `norms` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_stocks` | Owned by `stocks` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/stocks` warehouses; `/stocks/leftovers` leftovers report.

| Path | Permission |
|------|------------|
| `/stocks` | `—` |
| `/stocks/leftovers` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (5 ops).

| Op | Method | Path |
|----|--------|------|
| `stocks.list` | GET | `/stocks` |
| `stocks.get_edit` | GET | `/stocks/{id}/edit` |
| `stocks.create` | POST | `/stocks/new` |
| `stocks.update` | POST | `/stocks/{id}/edit` |
| `stocks.leftovers` | GET | `/stocks/leftovers` |

```bash
curl -sS -b jar.txt 'https://example/stocks?format=json'
curl -sS -b jar.txt 'https://example/stocks/leftovers?format=json'
```

## Notes

- Soft cabinets / stickers / catalog / norms.
