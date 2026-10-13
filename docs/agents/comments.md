# Comments

Product module `comments`: Enable when news/tickets need discussion threads. No own menu — embeds on core news cards. Required by contracts. Soft-used by workweeks, staff_reports, audio_llm. Keep on if any dependent module is enabled. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable comments --accept-risk --format=json
```

Or `[modules] comments=1` in `cfg/config.ini`.

## Dependencies

- Requires: `core` >=0.3.0

## Tables

| Table | Role |
|-------|------|
| `base_comments` | Owned by `comments` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- No own menu routes — comment thread embeds on core news/tickets via EventBus.
- Staff use `/news/{id}` to read/add comments.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (none — embed-only module).

## Notes

- Empty `agent.ops` — use `core.news.*` ops that include comments.
- Table `base_comments` owned by this module.
