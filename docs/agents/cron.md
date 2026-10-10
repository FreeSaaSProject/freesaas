# Platform cron

Hard tick only (no soft-cron on page hits). Modules register jobs in `bootstrap.php` via `Cron::register`; host crontab wakes them every 5 minutes.

## Host setup

```cron
*/5 * * * * curl -sS -H "X-FreeSaaS-Agent-Token: YOUR_TOKEN" "https://example.com/cron/tick" >/dev/null
```

CLI twin:

```bash
php bin/freesaas.php cron tick --format=json
php bin/freesaas.php cron tick --job=habits.reminders --force --format=json
```

Auth: same `app.agent_token` as `/agent/*` (not an `/agent/cron/*` alias).

| Surface | Auth | Role |
|---------|------|------|
| `GET /cron/status` | agent token | registry + recent runs JSON (`assistant: false`) |
| `POST /cron/tick` | agent token | run due / forced jobs |
| `GET/POST /cron` | session + SUPERADMIN | human UI + session JSON |
| `php bin/freesaas.php cron tick` | CLI | twin of tick |

`agent.ops`: `core.cron.status`, `core.cron.tick`, `core.cron.ui` — all `"assistant": false` (ops/MCP, not in-system chat tools).

## Register a job

```php
Cron::register('myid.nightly', [
    'module' => 'myid',
    'label' => 'myid.cron.nightly', // i18n
    'every' => 15,                  // minutes since last finish
    // or 'daily_at' => '09:00',
    'handler' => static function (): array {
        // domain work in-process — do not HTTP-loopback
        return ['ok' => true, 'processed' => 0, 'message' => ''];
    },
]);
```

Schedule v1: `every` and/or `daily_at` (HH:MM, site timezone). Disabled module or `enabled=0` in `base_cron_job_state` → skip. Tick uses flock + ~50s budget; leftover due jobs wait for the next tick.

## What belongs here

- Periodic drains (bots queue, gcal queue)
- Pull syncs (banks, terminals load/tickets, CRM imports)
- Daily generators (`daily_at`) once ported into FreeSaaS

## What does not

- Mac/Whisper pull workers (`/asr/voice-transcribe/worker`, `/audio/asr/*`) — they poll FreeSaaS
- Domain queue tables themselves — cron only *wakes* handlers
- Per-module `worker_token` HTTP jobs — keep for external/manual curl; tick calls the same services in-process

## Tables

- `base_cron_job_state` — last status / enable flag
- `base_cron_runs` — journal (pruned ~14 days / 500 rows)

Health: `platform.cron_stale` when registered jobs have not finished recently (crontab missing).

## Ops note (legacy host scripts)

Morning/evening per-user task generators that still live as separate hosting PHP scripts should be ported into a module and registered with `daily_at` (e.g. `08:00` / `20:00`), then removed from the host crontab in favor of `/cron/tick`.
