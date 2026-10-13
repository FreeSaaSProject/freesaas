# Poll invites

Product module `poll_invites`: Enable to send/fill poll invites. Requires polls. Soft clients/bonuses. Publishes poll_invites.* for bots and site channels. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable poll_invites --accept-risk --format=json
```

Or `[modules] poll_invites=1` in `cfg/config.ini`.

Permissions: `poll_invites.view`, `poll_invites.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `polls` >=0.1.0
- Soft: `clients` >=0.1.0, `bonuses` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_poll_invites` | Owned by `poll_invites` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/poll-invites` — invite instances; `/poll-invites/{id}/fill` staff fill UI.

| Path | Permission |
|------|------------|
| `/poll-invites` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (3 ops).

| Op | Method | Path |
|----|--------|------|
| `poll_invites.list` | GET | `/poll-invites` |
| `poll_invites.create` | POST | `/poll-invites/new` |
| `poll_invites.fill` | POST | `/poll-invites/{id}/fill` |

```bash
curl -sS -b jar.txt 'https://example/poll-invites?format=json'
```

## Notes

- Requires `polls`.
- Channels via EventBus for bots/site.
