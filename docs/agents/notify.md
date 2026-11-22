# Notifications and confirmations (`notify`)

Alpha module: event catalog + rules + delivery journal + confirmation tasks. Soft-depends on `bots` and `mail`.

## Concepts

- Domain modules register events on `notify.catalog` and emit `notify.event` (or answer `notify.scan` for timed stages).
- Rules in `base_notify_rules` decide recipients (chat / staff / role / flag / on_shift / subject), channels (`bot|email|inapp`), and confirm stages.
- Deliveries are journaled in `base_notify_deliveries` with `CLIENT_ID` (about) and `RCPT_KIND`/`RCPT_ID` (to whom).
- Confirm tasks live in `base_notify_tasks`; group buttons use callback `nt:<taskId>:<outcome>` via `bots.inbound`.

## Staff UI

| Path | Permission |
|------|------------|
| `/notify/rules` | `notify.manage` + SUPERADMIN |
| `/notify/journal` | `notify.journal` |
| `/notify/tasks` | `notify.tasks.view` / resolve |
| `/notify/settings` | `notify.manage` + SUPERADMIN |
| `/notify/send` | `notify.send` |

## Agent token (`app.agent_token`)

| Method | Path |
|--------|------|
| GET | `/agent/notify/status` |
| GET | `/agent/notify/catalog` |
| GET | `/agent/notify/rules` |
| GET | `/agent/notify/rules/get?id=` |
| POST | `/agent/notify/rules/save` |
| POST | `/agent/notify/rules/delete` |
| POST | `/agent/notify/rules/test` |
| GET/POST | `/agent/notify/settings` |
| GET | `/agent/notify/chats` |
| GET | `/agent/notify/journal` |
| GET | `/agent/notify/tasks` |
| POST | `/agent/notify/tasks/resolve` |
| POST | `/agent/notify/send` |
| GET | `/agent/notify/presets` |
| POST | `/agent/notify/presets/apply` |

Session `agent.ops` mirror the staff UI paths in `module.json`. Token twins are listed as `notify.token.*`.

## Quick-start packs

Staff UI `/notify/rules` (or `POST /agent/notify/presets/apply`):

| pack | needs | events |
|------|-------|--------|
| `orders_group` | chat | created + client cancel |
| `orders_confirm` | chat | confirm stages |
| `procedures_operator` | — | created/updated → operator |
| `vacations_group` | chat | remind |
| `backups_ops` | chat and/or staff | failed/succeeded/stale |
| `edge_ops` | chat and/or staff | down/up |
| `payments_issuers` | — | day payroll → issuer |
| `video_alerts` | chat | alert |

`pack=all_satisfied` applies every pack whose recipients are present. Names `preset …` make re-runs idempotent. Prefer packs after (or instead of) `data-migrate … legacy_settings` on a greenfield instance.

## Enable

```bash
php bin/freesaas.php module enable notify --accept-risk
php bin/freesaas.php migrate run --module=notify
php bin/freesaas.php data-migrate run --module=notify --id=legacy_settings
```

## Cron

`notify.tick` every 5 minutes (host `*/5` → `/cron/tick`): scan timed confirm stages, retries, escalations, sync delivery status from bot/mail queues.

## Producers (when notify is enabled)

| Module | Events | Legacy fallback |
|--------|--------|-----------------|
| `orders` | created/updated/cancelled/completed/confirm | `OrdersMaxNotify` |
| `procedures` | created/updated/cancelled | `ProcedureBotNotify` |
| `vacations` | `vacations.remind` | `VacationsGroupNotify` BotSend |
| `backups` | failed/succeeded/stale/test | `BackupNotify` bots |
| `core` | `core.edge_*` | `EdgeHostsNotify` bots |
| `video` | `video.alert` | `bots.send` channel |
| `payments` | day payroll request/cancel | `DayPayroll` BotSend to issuers |

Habit reminders (`MyTasksHabits`) stay on BotSend (interactive reply keyboards).

## Client history

Client card (`clients.view.profile`): two tabs — deliveries **to** the client (`RCPT_KIND=client`) and **about** the client (`CLIENT_ID`).
