# Catalog

Product module `catalog`: Enable for the product tree and goods used by procedures, stickers, norms, bonuses. Soft units/suppliers/procedures/sites. Core merchandising dictionary — enable early with warehouse modules. Includes admin price-list print (`/catalog/prices/print`). Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable catalog --accept-risk --format=json
```

Or `[modules] catalog=1` in `cfg/config.ini`.

Permissions: `catalog.view`, `catalog.edit`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `units`, `suppliers`, `procedures`, `sites` (host presets for price-list print)

## Tables

| Table | Role |
|-------|------|
| `base_categories` | Owned by `catalog` |
| `base_products` | Owned by `catalog` |
| `base_product_relations` | Owned by `catalog` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/catalog` browse tree; `/categories`, `/products` CRUD.
- `/catalog/prices/print` — printable price lists (oper-style). Query: `title`, `roots`, `host` (sites host id → roots+title), `kind` (0=all / 1=medical / 2=household), `main`/`strike` (`price`…`price5`|`none`), `hide_descr`, `show_discount`, `cols` (1|2), `hidden`, `print`. Soft presets from `base_site_hosts.catalog_root_ids` when `sites` is on — each host is a separate sheet title so brands (e.g. LikeSpa vs Balance) print independently.
- Stock qty shown when stocks/stickers enabled.

| Path | Permission |
|------|------------|
| `/catalog` | `catalog.view` |
| `/catalog/prices/print` | `catalog.view` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

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
| `catalog.prices_print` | GET | `/catalog/prices/print` |

```bash
curl -sS -b jar.txt 'https://example/catalog?format=json'
curl -sS -b jar.txt 'https://example/catalog/prices/print?format=json&host=1'
```

## Notes

- Soft units / suppliers / procedures / sites (price-list presets).
