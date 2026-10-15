# Contracts

Product module `contracts`: Enable for offer/contract workflow on top of comments. Hard-requires comments. Soft-used by payments (pay CTA) and staff_reports/audio_llm. Use when HR/ops track signed offers in the cabin. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable contracts --accept-risk --format=json
```

Or `[modules] contracts=1` in `cfg/config.ini`.

Permissions: `contracts.list`, `contracts.manage`.

## Dependencies

- Requires: `core` >=0.3.0, `comments` >=0.1.0
- Soft: `payments` >=0.1.10 (payroll settings UI for `deadline_penalty`)

## Human UI

- `/contracts` — offers/contracts list and actions.
- Deadline penalty and related payroll knobs: `/payments/settings` (superadmin). Legacy `/contracts/settings` redirects there when payments is on.

| Path | Permission |
|------|------------|
| `/contracts` | `contracts.list` |
| `/contracts/settings` | redirect → `/payments/settings` (`SUPERADMIN_ACCESS`) |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (2 ops). Settings: `payments.settings.get` / `payments.settings.update` (`deadline_penalty` when contracts is enabled).

| Op | Method | Path |
|----|--------|------|
| `contracts.list` | GET | `/contracts` |
| `contracts.action` | POST | `/contracts/action` |

```bash
curl -sS -b jar.txt 'https://example/contracts?format=json'
curl -sS -b jar.txt 'https://example/payments/settings?format=json'
```

## Notes

- Depends on `core` + `comments`.
- Payments module can show pay CTA when enabled.
- `cfg/modules/contracts.ini` → `[contracts] deadline_penalty` is written from `/payments/settings`.
