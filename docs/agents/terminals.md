# Terminals & therms

Product module `terminals`: device registry (`base_terminals`, `base_bracelets`), therm occupancy analytics (`base_termloads`, `base_fullstats`, `base_term_orders`), and HTTP sync jobs. Secrets/URLs live in `cfg/modules/terminals.ini` (not platform `config.ini`).

Live staff terminal UI (`/t`, `qt.php`, `api_terminal_*`) and Termy booking API stay on the site — not in this module. Scan analytics stay in `likespa` (`base_term_scans`).

## Enable

```bash
# Copy modules/terminals/config.example.ini → cfg/modules/terminals.ini
# Set 1c_* / worker_token / orders_url as needed

php bin/freesaas.php module enable terminals --format=json
php bin/freesaas.php module validate terminals
php bin/freesaas.php health --module=terminals
php bin/smoke.php
```

Ini flags: `registry_enabled`, `analytics_enabled`, `sync_enabled`.

Permissions: `terminals.view`, `terminals.edit`, `terminals.sync` (jobs use `worker_token`, not session).

## Human UI

| Path | Role |
|------|------|
| `/terminals` | Device list / CRUD |
| `/terminals/bracelets` | Bracelet UID ↔ NUM |
| `/terminals/termloads` | Day occupancy series |
| `/terminals/fullstats` | Ticket visits + classify |
| `/terminals/orders` | Term ticket orders |
| `/terminals/settings` | Superadmin: flags + secrets |

## Agent JSON (session)

Same routes as UI; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/terminals?format=json'
curl -sS -b jar.txt 'https://example/terminals/termloads?day=2026-10-06&format=json'
curl -sS -b jar.txt 'https://example/terminals/fullstats?day=2026-10-06&format=json'
curl -sS -b jar.txt 'https://example/terminals/orders?format=json'
curl -sS -b jar.txt 'https://example/terminals/settings?format=json'
```

## Sync jobs (worker_token)

Header `X-Terminals-Token` or `?token=` (like audio workers).

```bash
curl -sS -X POST -H "X-Terminals-Token: $TOKEN" 'https://example/terminals/jobs/load'
curl -sS -X POST -H "X-Terminals-Token: $TOKEN" \
  'https://example/terminals/jobs/tickets?day=2026-10-06'
curl -sS -X POST -H "X-Terminals-Token: $TOKEN" 'https://example/terminals/jobs/orders'
```

- `load` → 1C `club_workload` → `base_termloads` (minute sample)
- `tickets` → 1C `club_workload_detail` → replace day in `base_fullstats`
- `orders` → `orders_url?start=lastId` → safe parse into `base_term_orders` (no remote SQL exec)

## Config keys

| Key | Purpose |
|-----|---------|
| `1c_url`, `1c_auth`, `1c_apikey`, `1c_terminal`, `1c_uid` | 1C club API |
| `orders_url` | Volga Termy orders feed |
| `worker_token` | Jobs auth |
| `booking_terminal_id`, `booking_admin_id` | Future booking defaults |

Per-device `CODE` stays in `base_terminals` (masked in UI/JSON lists).

## Notes

- Column `IS_MASSЕUR_MODE` keeps Cyrillic Е for shared legacy DB compatibility.
- When `terminals` is enabled, likespa term-scans UI soft-resolves terminal names.
