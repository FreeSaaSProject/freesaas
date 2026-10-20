# Suppliers

Product module `suppliers`: Enable for supplier cards used by catalog relations and sticker receipts. Soft-used by ledger and stickers. Dictionary module — enable with warehouse/catalog workflows. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable suppliers --accept-risk --format=json
```

Or `[modules] suppliers=1` in `cfg/config.ini`.

Permissions: `suppliers.view`, `suppliers.edit`.

## Dependencies

- Requires: `core` >=0.3.0

## Tables

| Table | Role |
|-------|------|
| `base_suppliers` | Owned by `suppliers` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/suppliers` — suppliers dictionary.

| Path | Permission |
|------|------------|
| `/suppliers` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `suppliers.list` | GET | `/suppliers` |
| `suppliers.get` | GET | `/suppliers/{id}/edit` |
| `suppliers.create` | POST | `/suppliers/new` |
| `suppliers.update` | POST | `/suppliers/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/suppliers?format=json'
```

## Notes

- Search q in both Cyrillic and Latin (озон/ozon).
