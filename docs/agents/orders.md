# Orders

Product module `orders`: Enable for site order list and day feed into calendar. Requires clients. Soft catalog/procedures/bots. Required by calendar. Use when the public site posts orders into FreeSaaS. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable orders --accept-risk --format=json
cp modules/orders/config.example.ini cfg/modules/orders.ini
```

Or `[modules] orders=1` in `cfg/config.ini`.

Permissions: `orders.view`, `orders.edit`. Settings: SUPERADMIN `/orders/settings`.

## Dependencies

- Requires: `core` >=0.3.0, `clients` >=0.1.0
- Soft: `catalog`, `procedures`, `sites`, `booking`, `bots` (Max-group notify)

## Tables

| Table | Role |
|-------|------|
| `base_orders` | Owned by `orders` (`SOURCE` = channel marker) |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

### `SOURCE` (channel)

Callers must set on create (technical codes, not translated):

| Source | Caller |
|--------|--------|
| `api_termy` | `/api_termy.php` book |
| `api` | `/api.php` order-mode book |
| `site` | storefront cart / checkout |
| `staff` | staff `/booking/new`, `/booking/book` |

Other modules add channel codes via EventBus `orders.sources` (`$payload['sources'][] = 'code'`, `[a-z0-9_]`); `OrdersConfig::sources()` is the effective list. Example: `client_mcp` adds `mcp` for public client MCP bookings ([client-mcp.md](client-mcp.md)).

Client-facing channels read `OrdersRepository::clientState($order)` (`state` active|past|cancelled|completed|other, `paid`, `cancellable`) instead of parsing `STATUS`. `orders` subscribes `client_mcp.payment` to expose a still-payable legacy acquiring link (`OrdersRepository::pendingPaymentLink`).

## Max-group notify

When `max_notify_enabled=1` and `max_notify_chat_id` set (`cfg/modules/orders.ini` or `/orders/settings`):

1. `OrdersRepository::create` / `cancelForClient` → `OrdersMaxNotify`
2. Enqueue via `BotSend` (`chat_id` + `platform=max`) into `base_telegram_sends`
3. Best-effort `BotDrain::run` + cron `bots.sends_drain`

Needs `bots` enabled and `staff_max_token` (bot must be in the Max group). Message body includes raw `Источник: api_termy` (etc.). Optional single title overrides: `max_notify_title` / `max_notify_title_cancel`.

Events: `orders.created`, `orders.cancelled`.

```bash
# After enabling bots + orders migrate
php bin/freesaas.php migrate run --module=orders --format=json
php bin/freesaas.php migrate run --module=bots --format=json
```

## Human UI

- `/orders` — website / advance orders list and edit
- `/orders/settings` — Max notify (superadmin)
- Calendar day embeds orders via EventBus when calendar on

| Path | Permission |
|------|------------|
| `/orders` | `orders.view` |
| `/orders/settings` | SUPERADMIN |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

| Op | Method | Path |
|----|--------|------|
| `orders.list` | GET | `/orders` |
| `orders.get` | GET | `/orders/{id}` |
| `orders.get_edit` | GET | `/orders/{id}/edit` |
| `orders.update` | POST | `/orders/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/orders?format=json'
curl -sS -b jar.txt 'https://example/orders/settings?format=json'
```

## Notes

- Table `base_orders` shared with site/booking.
- Do not list as exclusive owner of foreign tables you only ALTER.
