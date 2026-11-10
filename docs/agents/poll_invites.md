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

- `/poll-invites` — day hub (default **today**; `day=all` = all days; ←→ keyboard via `.pager`). Filters: `progress=unviewed` (chip forces `day=all`), `unfilled`, `id_resp`, poll, search. Rows: transcript snippet, procedure, score. Badge **новое** + «Просмотрено» → `RESP_USERS_VIEWED`.
- Superadmin + `poll_speech` enabled + concrete day: **Fill from speech** / **Recreate day from speech** → `POST /poll-speech/fill-day` (returns to this list via `ret`).
- Bulk ops need `poll_invites.delete` (superadmin bypasses `Auth::can`): plain delete, plus **Delete unfilled** (confirm + checks: never started/completed, empty answers/scores). Empty selection deletes unfilled **only inside the current list filters** (day / poll / progress / q / …); without a filter the action errors.
- Legacy `/poll-invites/unviewed` redirects to the list filter (POST mark still works for old clients).
- Invite cards `/poll-invites/{id}/edit` and `/fill` stay **director-only** (`SUPERADMIN_ACCESS` + edit). Superadmin edit: score select (1–10 via `poll_speech` mapping) + embedded cabin player/transcript from `record=` in LOG (needs `audio` module; no separate `audio.view` grant).
- Oral mark API: `POST /poll-invites/oral-asked` (body: `id_proc`, optional `id_poll`).
- Speech **settings** stay at `/poll-speech/settings` (no separate speech list route).

## Agent JSON

| Op | Method | Path |
|----|--------|------|
| `poll_invites.list` | GET | `/poll-invites` (`progress=unviewed` …) |
| `poll_invites.unviewed.mark` | POST | `/poll-invites` (`action=mark_viewed`) |
| `poll_invites.create` | POST | `/poll-invites/new` |
| `poll_invites.oral_asked` | POST | `/poll-invites/oral-asked` |
| `poll_invites.fill` | POST | `/poll-invites/{id}/fill` |

## Notes

- `oral_poll_id` may be set from `poll_speech` settings (synced into `poll_invites` ini).
- See also [`poll_speech.md`](poll_speech.md).
