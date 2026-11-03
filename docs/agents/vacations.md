# Vacations

Product module `vacations`: Enable for staff vacation register and approvals. Depends only on core. Soft `bots` for group reminders. Team rail (next to shifts); independent of calendar booking. Catalog maturity: `release`.

## Enable

```bash
php bin/freesaas.php module enable vacations --format=json
```

Or `[modules] vacations=1` in `cfg/config.ini`.

Permissions: `vacations.view`, `vacations.edit`, `vacations.delete`.

## Dependencies

- Requires: `core` >=1.0.0
- Optional: `bots` (group remind), `assistant_groups` (chat_id hints in settings), `bi`

## Tables

| Table | Role |
|-------|------|
| `base_vacations` | Owned by `vacations` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/vacations` — leave register and sign workflow (menu: Team, after Shifts).
- `/vacations/settings` — group remind (superadmin): days before, chat_id, platform, message template. Copy `modules/vacations/config.example.ini` → `cfg/modules/vacations.ini`.

| Path | Permission |
|------|------------|
| `/vacations` | `vacations.view` |
| `/vacations/settings` | `vacations.edit` + `SUPERADMIN_ACCESS` |

## Group remind (cron)

Job `vacations.upcoming_remind` (`daily_at` from `remind_daily_at`, default `09:00`):

1. For each N in `remind_days_before` (default `5`), find rows with `DT_START` on today+N.
2. Filters: `remind_types` (default `1,2`), optional `remind_show_user_only`.
3. Enqueue via `BotSend` to `remind_chat_id` / `remind_platform` with `dedupe_key=vacations.remind.{id}.d{N}`.
4. Drain needs `bots` + staff Max/Telegram token (`bots.sends_drain`).

Test send: POST `/vacations/settings` with `action=test_remind`.

Force job:

```bash
php bin/freesaas.php cron tick --job=vacations.upcoming_remind --force --format=json
```

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

| Op | Method | Path |
|----|--------|------|
| `vacations.list` | GET | `/vacations` |
| `vacations.get` | GET | `/vacations/{id}/edit` |
| `vacations.create` | POST | `/vacations/new` |
| `vacations.update` | POST | `/vacations/{id}/edit` |
| `vacations.settings.get` | GET | `/vacations/settings` |
| `vacations.settings.save` | POST | `/vacations/settings` |

```bash
curl -sS -b jar.txt 'https://example/vacations?format=json'
```

## Notes

- List may be scoped to current user unless privileged (`CAN_SEE_SUM_OTHERS`; group drafts with `assistant_groups.group_read_unrestricted=1` read unscoped).
- `vacations.update` is a patch: send `id` + changed fields (`dt_start`/`dt_end` as `YYYY-MM-DD`); omitted fields keep stored values.
- Moving a vacation in the assistant: `workweeks` declares a relation, so shifts inside the new period are added as `workweeks.delete` steps of the same plan (one Confirm; needs `app.agent_allow_delete=1`).
- Message placeholders: `{acronym}` `{name}` `{start}` `{end}` `{days}`. Override via `remind_message` or i18n `vacations.remind.message`.
