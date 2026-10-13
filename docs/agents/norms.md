# Consumption norms

Product module `norms`: Enable for default material norms on products. Requires catalog. Soft-used by stocks leftovers logic. Useful when procedures/writeoffs should know default consumptions. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable norms --accept-risk --format=json
```

Or `[modules] norms=1` in `cfg/config.ini`.

Permissions: `norms.view`, `norms.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `catalog` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_good_defaults` | Owned by `norms` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/norms` — default consumptions (`base_good_defaults`).
- Also embedded on catalog product edit when enabled.

| Path | Permission |
|------|------------|
| `/norms` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `norms.list` | GET | `/norms` |
| `norms.get` | GET | `/norms/{id}/edit` |
| `norms.create` | POST | `/norms/new` |
| `norms.update` | POST | `/norms/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/norms?format=json'
```

## Notes

- Soft dep of stocks / stickers workflows.
