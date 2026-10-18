# Laundry

Product module `laundry`: clean linen ledger for the wash cycle (ship → laundry → accept). Stock by account × item type. Catalog maturity: `beta`.

Does **not** use or migrate `sheets` / `base_sheet_*`. Legacy sheets can be removed later.

## Enable

```bash
php bin/freesaas.php module enable laundry --accept-risk --format=json
```

Or `[modules] laundry=1` in `cfg/config.ini`.

Copy `modules/laundry/config.example.ini` → `cfg/modules/laundry.ini` if needed.

Permissions: `laundry.view`, `laundry.ship`, `laundry.accounts`, `laundry.edit`, `laundry.delete`.

| Key | Default | Role |
|-----|---------|------|
| `ticket_news_id` | `78` | Ticket that embeds the laundry panel |
| `terms_account_id` | `1` | Floor / terms account (after seed) |
| `laundry_account_id` | `2` | Laundry account (after seed) |

## Dependencies

- Requires: `core` >=0.3.0

## Tables

| Table | Role |
|-------|------|
| `base_laundry_accounts` | Location accounts (seed: ТЕРМЫ, ИП Терем стирка) |
| `base_laundry_item_types` | sheets / tees / shorts / curtains |
| `base_laundry_moves` | Signed ledger (+ transfers linked) |
| `base_laundry_shipments` / `_lines` | Outbound lots with open qty |
| `base_laundry_returns` / `_lines` | Accept against shipment lines |

## Day zero

1. Enable module (migrations seed accounts + types).
2. Physical count.
3. `/laundry/inventory` — set absolute facts (opening).
4. Daily: ship / accept from `/laundry` or ticket `#78` embed.

## Human UI

| Path | Permission |
|------|------------|
| `/laundry` | `laundry.view` |
| `/laundry/ship`, `/laundry/accept` | `laundry.ship` |
| `/laundry/inventory` | `laundry.edit` |
| `/laundry/shipments`, `/laundry/moves` | `laundry.view` |
| `/laundry/accounts` | `laundry.accounts` |
| `/laundry/types` | `laundry.edit` |
| `/laundry/settings` | `laundry.edit` — linked ticket + default accounts |

Ticket `/news/{ticket_news_id}` shows the laundry panel via `core.news_item.section` (ship/accept POST via `core.news_item.handle_post`). Comments on the ticket are chat only — not stock.

## Agent JSON

| Op | Method | Path |
|----|--------|------|
| `laundry.moves.list` | GET | `/laundry/moves` |
| `laundry.shipments.list` | GET | `/laundry/shipments` |
| `laundry.ship.create` | POST | `/laundry/ship` |
| `laundry.accept.create` | POST | `/laundry/accept` |
| `laundry.inventory.apply` | POST | `/laundry/inventory` |
