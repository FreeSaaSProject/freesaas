# Orders

Product module `orders`: Enable for site order list and day feed into calendar. Requires clients. Soft catalog/procedures. Required by calendar. Use when the public site posts orders into FreeSaaS. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable orders --accept-risk --format=json
```

Or `[modules] orders=1` in `cfg/config.ini`.

Permissions: `orders.view`, `orders.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `clients` >=0.1.0
- Soft: `catalog` >=0.1.0, `procedures` >=0.1.0, `sites` >=0.1.0, `booking` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_orders` | Owned by `orders` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/orders` — website / advance orders list and edit.
- Calendar day embeds orders via EventBus when calendar on.

| Path | Permission |
|------|------------|
| `/orders` | `orders.view` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `orders.list` | GET | `/orders` |
| `orders.get` | GET | `/orders/{id}` |
| `orders.get_edit` | GET | `/orders/{id}/edit` |
| `orders.update` | POST | `/orders/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/orders?format=json'
```

## Notes

- Table `base_orders` shared with site/booking.
- Do not list as exclusive owner of foreign tables you only ALTER.
