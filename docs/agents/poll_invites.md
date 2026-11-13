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

## Public respondent link (`/q/{code}`)

- Personal invite with a 24-char random `CODE` (`PollPublic::create`); codes shorter than 16 chars are never served publicly. No login; CSRF on POST; `noindex` layout (`layout_auth`).
- One page with every question: options (radio / checkboxes), free text when `req_text_len > 0` or the question has **no options** (text-only questions now advance the walk when text is present). Saved via `saveAnswersFromChannel(..., 'public')`; incomplete submit = draft + highlighted missing question. After completion the respondent may edit answers via the same link until `DT_EXPIRE`.
- Personalisation: `TEXT_ADMIN_TO_USER` (intro), poll `descr_site`, per-question `descr_site` as hint; `PERSONAL_JSON` (`skip_qids`, `hints{qid}`, `vars`); placeholders `{client.name}` / `{client.first_name}` + module values via EventBus `poll_invites.public.context` (CRM adds `{company.name}`, `{deal.title}`, `{manager.name}`).
- Follow-up: `POST /poll-invites/{id}/refill` → new invite, new link, previous answers prefilled, personalisation copied; old invite stays as history; emits `poll_invites.refilled`.
- JSON twin (bots / external agents): `GET /q/{code}?format=json` → `state`, `questions[]`, `answers`; `POST` `answers[qid]` / `texts[qid]` → `{completed, missing_qid}`.
- CRM sends these from the deal card (see [`crm.md`](crm.md)).

## Agent JSON

| Op | Method | Path |
|----|--------|------|
| `poll_invites.list` | GET | `/poll-invites` (`progress=unviewed` …) |
| `poll_invites.unviewed.mark` | POST | `/poll-invites` (`action=mark_viewed`) |
| `poll_invites.create` | POST | `/poll-invites/new` |
| `poll_invites.oral_asked` | POST | `/poll-invites/oral-asked` |
| `poll_invites.fill` | POST | `/poll-invites/{id}/fill` |
| `poll_invites.refill` | POST | `/poll-invites/{id}/refill` (`expire_days`) |
| `poll_invites.public` | GET/POST | `/q/{code}` (public, `assistant: false`) |

## Notes

- `oral_poll_id` may be set from `poll_speech` settings (synced into `poll_invites` ini).
- See also [`poll_speech.md`](poll_speech.md).
