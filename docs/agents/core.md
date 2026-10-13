# FreeSaaS Core

Platform module `core`: always on — cannot be disabled. Owns session auth, staff users/statuses, news feed, settings, `/modules` catalog, schema migrate UI, and shared chrome. Soft-depends on bots for messenger-related staff fields. Enable other modules on top of core; never try to turn core off.

## Enable

Always enabled (`[modules] core=1`). Do not disable.

Permissions: `core.home`, `core.modules`, `core.db`, `core.users`, `core.statuses`, `core.news`, `core.news_statuses`, `core.settings`.

## Dependencies

- Soft: `bots`, `contracts`, `gcal`, `stocks` (optional features / embeds)

## Tables

| Table | Role |
|-------|------|
| `base_users` | Owned by `core` |
| `base_system` | Owned by `core` |
| `base_modules` | Owned by `core` |
| `base_schema_migrations` | Owned by `core` |
| `base_news` | Owned by `core` |
| `base_news_statuses` | Owned by `core` |
| `base_news_logs` | Owned by `core` |
| `base_news_checklist` | Owned by `core` |
| `base_cron_job_state` | Owned by `core` |
| `base_cron_runs` | Owned by `core` |
| `base_admin_statuses` | Owned by `core` |
| `base_views` | Owned by `core` |
| `base_likes` | Owned by `core` |
| `base_user_prefs` | Owned by `core` |
| `base_user_login_links` | Owned by `core` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- Home `/`, dashboard, news/tickets `/news`, staff `/users`, roles `/statuses`.
- Modules catalog `/modules`, settings `/settings`, DB hub `/db`, cron journal `/cron`.
- Staff how-to for product modules: `/help` (`core.help.search` / `core.help.get`).
- Platform lifecycle (enable/migrate/bootstrap) stays on CLI or `/agent/*` + `agent_token` — see [agent-surfaces.md](agent-surfaces.md), [cron.md](cron.md), [db-backup.md](db-backup.md).

| Path | Permission |
|------|------------|
| `/` | `—` |
| `/news?type=2` | `—` |
| `/news-statuses` | `—` |
| `/users` | `—` |
| `/statuses` | `—` |
| `/settings` | `—` |
| `/modules` | `—` |
| `/db` | `—` |
| `/cron` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (41 ops).

| Op | Method | Path |
|----|--------|------|
| `core.cron.status` | GET | `/cron/status` |
| `core.cron.tick` | POST | `/cron/tick` |
| `core.cron.ui` | GET | `/cron` |
| `core.jobs.habits_reminders` | POST | `/core/jobs/habits-reminders` |
| `core.modules.list` | GET | `/modules` |
| `core.modules.install` | POST | `/modules` |
| `core.modules.uninstall` | POST | `/modules` |
| `core.modules.get` | GET | `/modules/{id}` |
| `core.health.detail` | GET | `/health/detail` |
| `core.doctor` | GET | `/doctor` |
| `core.help.search` | GET | `/help` |
| `core.help.get` | GET | `/help/{id}` |
| `core.users.list` | GET | `/users` |
| `core.users.get` | GET | `/users/{id}` |
| `core.users.get_edit` | GET | `/users/{id}/edit` |
| `core.users.copy_sources` | GET | `/users/new` |
| `core.users.create` | POST | `/users/new` |
| `core.users.clone` | POST | `/users/new` |
| `core.users.update` | POST | `/users/{id}/edit` |
| `core.statuses.list` | GET | `/statuses` |
| `core.statuses.get` | GET | `/statuses/{id}/edit` |
| `core.statuses.create` | POST | `/statuses/new` |
| `core.statuses.update` | POST | `/statuses/{id}/edit` |
| `core.db.status` | GET | `/db` |
| `core.db.run_schema` | POST | `/db` |
| `core.db.run_data` | POST | `/db` |
| `core.db.dump` | POST | `/db` |
| `core.db.restore` | POST | `/db` |
| `core.db.upload` | POST | `/db` |
| `core.news_statuses.list` | GET | `/news-statuses` |
| `core.news_statuses.get` | GET | `/news-statuses/{id}/edit` |
| `core.news_statuses.create` | POST | `/news-statuses/new` |
| `core.news_statuses.update` | POST | `/news-statuses/{id}/edit` |
| `core.news.list` | GET | `/news` |
| `core.news.get` | GET | `/news/{id}` |
| `core.news.get_edit` | GET | `/news/{id}/edit` |
| `core.news.create` | POST | `/news/new` |
| `core.news.update` | POST | `/news/{id}/edit` |
| `core.news.act` | POST | `/news/{id}` |
| `core.settings.get` | GET | `/settings` |
| `core.settings.save` | POST | `/settings` |

```bash
curl -sS -b jar.txt 'https://example/cron/status?format=json'
curl -sS -b jar.txt 'https://example/cron?format=json'
```

## Notes

- Cannot be disabled.
- `SUPERADMIN_ACCESS` is a bypass for directors only.
