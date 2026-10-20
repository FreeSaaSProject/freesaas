# Units of measure

Product module `units`: Enable for UoM on products and warehouse docs. Soft-used by catalog and stickers. Lightweight dictionary — safe to enable whenever catalog/stocks are used. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable units --accept-risk --format=json
```

Or `[modules] units=1` in `cfg/config.ini`.

Permissions: `units.view`, `units.edit`.

## Dependencies

- Requires: `core` >=0.3.0

## Tables

| Table | Role |
|-------|------|
| `base_units` | Owned by `units` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/units` — units of measure.

| Path | Permission |
|------|------------|
| `/units` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `units.list` | GET | `/units` |
| `units.get` | GET | `/units/{id}/edit` |
| `units.create` | POST | `/units/new` |
| `units.update` | POST | `/units/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/units?format=json'
```

## Notes

- Used by catalog products.
