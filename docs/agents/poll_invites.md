# Poll invites

Product module `poll_invites`: invite instances for poll definitions. Requires polls. Soft clients/bonuses/calendar/procedures. Publishes `poll_invites.*` for bots and site channels. Catalog maturity: `beta`.

Oral calendar button and speech fill live in **`poll_speech`** (when enabled). This module keeps `markOralAsked` / `POST /poll-invites/oral-asked` and `saveAnswers` / `saveAnswersFromChannel`.

## Enable

```bash
php bin/freesaas.php module enable poll_invites --accept-risk --format=json
```

Or `[modules] poll_invites=1` in `cfg/config.ini`.

Permissions: `poll_invites.view`, `poll_invites.edit`, `poll_invites.delete`.

## Dependencies

- Requires: `core` >=1.0.0, `polls` >=0.1.0
- Soft: `clients`, `bonuses`, `calendar`, `procedures`, `bi`

## Tables

| Table | Role |
|-------|------|
| `base_poll_invites` | Owned by `poll_invites` |

## Human UI

- `/poll-invites` — invite list (transcript snippet, procedure, score); filters include responsible staff.
- `/poll-invites/unviewed` — **group review** for responsible staff (`RESP_USERS` / procedure executor|admin). Shows answers + transcript **without** opening invite cards. Explicit «Viewed» writes `#uid#` into `RESP_USERS_VIEWED`. Notice chip `НО{n}` / `UF{n}`.
- Invite cards `/poll-invites/{id}/edit` and `/fill` stay **director-only** (`SUPERADMIN_ACCESS` + edit).
- Oral mark API: `POST /poll-invites/oral-asked` (body: `id_proc`, optional `id_poll`).

## Agent JSON

| Op | Method | Path |
|----|--------|------|
| `poll_invites.list` | GET | `/poll-invites` |
| `poll_invites.unviewed` | GET | `/poll-invites/unviewed` |
| `poll_invites.unviewed.mark` | POST | `/poll-invites/unviewed` |
| `poll_invites.create` | POST | `/poll-invites/new` |
| `poll_invites.oral_asked` | POST | `/poll-invites/oral-asked` |
| `poll_invites.fill` | POST | `/poll-invites/{id}/fill` |

## Notes

- `oral_poll_id` may be set from `poll_speech` settings (synced into `poll_invites` ini).
- See also [`poll_speech.md`](poll_speech.md).
