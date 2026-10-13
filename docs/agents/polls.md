# Polls

Product module `polls`: Enable to define reusable polls. Soft procedures/catalog. Required by poll_invites. Universal — not spa-specific; pair with poll_invites for staff fill. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable polls --accept-risk --format=json
```

Or `[modules] polls=1` in `cfg/config.ini`.

Permissions: `polls.view`, `polls.edit`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `procedures` >=0.1.0, `catalog` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_polls` | Owned by `polls` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/polls` — poll definition tree.

| Path | Permission |
|------|------------|
| `/polls` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (2 ops).

| Op | Method | Path |
|----|--------|------|
| `polls.list` | GET | `/polls` |
| `polls.get` | GET | `/polls/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/polls?format=json'
```

## Notes

- Universal; delivery channels via EventBus / poll_invites.
