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
- Soft: `payments` >=0.1.0

## Human UI

- `/contracts` — offers/contracts list and actions.
- `/contracts/settings` — module settings.

| Path | Permission |
|------|------------|
| `/contracts` | `—` |
| `/contracts/settings` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `contracts.list` | GET | `/contracts` |
| `contracts.action` | POST | `/contracts/action` |
| `contracts.settings.get` | GET | `/contracts/settings` |
| `contracts.settings.update` | POST | `/contracts/settings` |

```bash
curl -sS -b jar.txt 'https://example/contracts?format=json'
curl -sS -b jar.txt 'https://example/contracts/settings?format=json'
```

## Notes

- Depends on `core` + `comments`.
- Payments module can show pay CTA when enabled.
