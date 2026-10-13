# Catalog

Product module `catalog`: Enable for the product tree and goods used by procedures, stickers, norms, bonuses. Soft units/suppliers/procedures. Core merchandising dictionary — enable early with warehouse modules. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable catalog --accept-risk --format=json
```

Or `[modules] catalog=1` in `cfg/config.ini`.

Permissions: `catalog.view`, `catalog.edit`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `units` >=0.1.0, `suppliers` >=0.1.0, `procedures` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_categories` | Owned by `catalog` |
| `base_products` | Owned by `catalog` |
| `base_product_relations` | Owned by `catalog` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/catalog` browse tree; `/categories`, `/products` CRUD.
- Stock qty shown when stocks/stickers enabled.

| Path | Permission |
|------|------------|
| `/catalog` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (10 ops).

| Op | Method | Path |
|----|--------|------|
| `catalog.browse` | GET | `/catalog` |
| `catalog.browse.folder` | GET | `/catalog/{id}` |
| `catalog.browse.write` | POST | `/catalog` |
| `catalog.browse.folder.write` | POST | `/catalog/{id}` |
| `catalog.categories.get_edit` | GET | `/categories/{id}/edit` |
| `catalog.categories.create` | POST | `/categories/new` |
| `catalog.categories.update` | POST | `/categories/{id}/edit` |
| `catalog.products.get_edit` | GET | `/products/{id}/edit` |
| `catalog.products.create` | POST | `/products/new` |
| `catalog.products.update` | POST | `/products/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/catalog?format=json'
```

## Notes

- Soft units / suppliers / procedures.
