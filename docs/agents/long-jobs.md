# LongJob — heavy / long staff operations

Platform facade: [`app/LongJob.php`](../../app/LongJob.php). Store/registry: [`app/LongJob/`](../../app/LongJob/). Shared UI: [`assets/js/longjob.js`](../../assets/js/longjob.js) + [`modules/core/views/_longjob_panel.php`](../../modules/core/views/_longjob_panel.php) (CSS `.db-job-*` in `assets/css/core.css`). Streaming iframe helper: [`app/StreamProgress.php`](../../app/StreamProgress.php) (aliases: SummaryProgress / ReportProgress / CoachProgress).

**SSE is not used.** Protocol is short HTTP **POST ticks** (shared-hosting safe).

## When to use what

| Need | Use |
|------|-----|
| Staff/agent op that can exceed proxy/PHP wall (dump, mass delete, LLM drain) | **LongJob** (`start` → `tick` → `cancel`) |
| Scheduled background | **Cron::register** — [`cron.md`](cron.md) |
| Edge worker queue (audio/video/CRM bridges) | Token `jobs/*` — not browser LongJob |
| Small list selection | Sync **BulkActions** |
| Live log inside one request (report/coach stream) | **StreamProgress** (optional UX), still prefer ticks when possible |

**Rule for new modules:** default to LongJob for anything multi-second or multi-chunk. Do not invent a private `*-job.json` poller or copy a Progress class.

## Module-owned parameters (no hardcode in LongJob core)

Budgets, chunk sizes, nested restore crumbs live in the **owning module**. LongJob only applies a platform safety ceiling (1–600s) and merges `defaults` / named `contexts`.

```php
LongJob::register('myid.heavy_op', [
  'module' => 'myid',
  'permission' => 'myid.edit',
  'defaults' => ['tick_seconds' => 40, 'chunk' => 500],
  'contexts' => [
    'ui'  => ['tick_seconds' => 25],  // proxy-safe
    'cron'=> ['tick_seconds' => 40],
    'cli' => ['tick_seconds' => 90],
  ],
  'agent' => ['mode' => 'tick', 'assistant' => false],
  'on_start' => [MyLongJob::class, 'onStart'],
  'tick' => [MyLongJob::class, 'tick'],
  'on_cancel' => [MyLongJob::class, 'onCancel'], // optional
]);

LongJob::start('myid.heavy_op', $payload, ['context' => 'ui']);
// or override: ['tick_seconds' => 20]
```

Handler reads `$job['tick_seconds']`. Nested ops (e.g. backups replica → DbJobs restore crumb 8–25s) stay **inside** the module — LongJob must not special-case `replica` / `db`.

## Public job DTO

`LongJob::public($job)` → `id`, `type`, `status` (`running|done|error|cancelled`), `progress` / `progress_overall` / `progress_detail`, `message`, `log[]`, `error`, `result`, `elapsed`, `eta_seconds?`, `tick_seconds?`, plus legacy-friendly `op` / `progress_table`.

**Cancel vs tick race:** ticks take a non-blocking `.lock` (one crumb at a time). Cancel writes `cancelled` immediately. Tick/`LongJob::save` persist a `running` snapshot only via `LongJobStore::saveIfRunning` (JSON `LOCK_EX` + re-read) — a concurrent cancel is never overwritten back to `running`. The in-flight crumb may still finish; the next tick sees terminal status and stops.

## Agent surfaces

| Surface | Mode |
|---------|------|
| Platform `/agent/*` + token (e.g. `AgentOps::dbDump`) | **blocking** — keep sync API; inside may loop ticks (`LongJob::runUntilDone` or `DbTools::dump`) |
| Product `agent.ops` session JSON | **tick** pair `*.start` + `*.tick` (+ cancel), or one blocking op with `assistant: false` |
| CLI `php bin/freesaas.php …` | blocking / `runUntilDone` / module `pull()` |
| In-system assistant | Destructive long ops: `assistant: false`, or start-only returning `job_id` |

Document in each op `description` whether the client must **pump ticks** or the call **blocks until done**.

Example (tick): `backups.replica.pull` → `backups.replica.tick`.  
Example (blocking): `POST /agent/db/dump`.

Replica wipe + public `tick_token` auth stays in **backups** — LongJob does not generalize post-wipe auth.

## UI mount

`longjob.js` loads in the layout **before** `$content` so inline page scripts can call `LongJobPanel` immediately. If a page still races, wait for `window.LongJobPanel` (or `DOMContentLoaded`) before `mount` / `bindForms`. UI tick budgets should stay under typical FastCGI idle (~30s); `/db` passes `tick_seconds: 20`.

```php
<?= View::capture('_longjob_panel', [
  'longjobId' => 'my-job',
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
```

```js
LongJobPanel.mount({
  root: document.getElementById('my-job-overlay'),
  startUrl: '/myid/longjob',
  tickUrl: '/myid/longjob',
  cancelUrl: '/myid/longjob',
  csrf: '…',
  dualBars: false,
  i18n: { /* core.longjob.* */ }
}).runFormData(fd, {});
```

`/db` uses the same widget via `LongJobPanel.bindForms` on `form.db-job-form`.

## Checklist for a new handler

1. `LongJob::register` in module `bootstrap.php` with `defaults` / `contexts` / `agent`
2. `on_start` + `tick` (+ optional `on_cancel`); idempotent crumbs; cancel-safe cleanup
3. POST route(s) for start/tick/cancel (module keeps its paths — no global `/long-jobs`)
4. i18n for log lines; `core.longjob.*` for chrome
5. `module.json` → `routes.pages` + `agent.ops` (`assistant: false` if destructive)
6. Prefer UI overlay; CLI/agent may call `runUntilDone`
7. Do not put secrets in job JSON; drop bulky buffers before `save`

## Reference implementations

| Type | Notes |
|------|--------|
| `db.*` | [`DbJobs`](../../app/Db/DbJobs.php) store under `var/db_jobs/`; UI `/db`; AgentOps blocking dump/restore |
| `poll_invites.delete_unfilled` | Filter-wide delete when N ≥ 200 → LongJob; `/poll-invites/longjob` |
| `audio_llm.study_process` | Process-until-done via ticks; `/audio/llm-studies/longjob` |
| `audio_llm.review_moderation` | Build + LLM ticks on `/audio/review-moderate` |
| `backups.replica.check` | LongJob store; UI `/backups/replica` check button |
| `backups.replica.pull` | Domain `replica-job.json` + LongJobPanel; ticks via public `/backups/replica/tick` + `tick_token` after wipe |
| `backups.run` | Domain runs table + LongJobPanel on `/backups`; POST `/backups/run` job_start/tick |
| `updates.check` / `updates.apply` | Domain `var/updates` jobs + LongJobPanel on `/updates` (found-list chrome kept) |
| `audio_script_coach.work` | LongJobPanel drain; one phase per `/audio/script-coach/work` tick (StreamProgress optional) |
| `audio_llm.review_moderation` | LongJobPanel on day list embed; report HTML via `onDone` |
| `audio_dicts.suggest_descr` | Bulk LLM descr; one dict per tick; `/audio/dicts/longjob` |
| `backups.cdc_install` / `cdc_uninstall` | Settings CDC buttons; table/trigger crumbs |
| `liquid_assets.tinvest_sync` | One brokerage account per tick; pending fund confirm via `sync_job` |
| `audio.ingest` | Inbox batches via `IngestRunner`; `/audio/ingest` job ticks |
| `banks.sync` | Connection Sync now + ledger `/ledger/moves` sync-account chrome; one account link per tick |
| `yclients.import` | Clients page crumbs / staff one-shot; `/yclients/import/longjob` |
| `bitrix.import` | Entity page crumbs; `/bitrix/import/longjob` |
| `amocrm.import` | Entity page crumbs; `/amocrm/import/longjob` |
| `clientics.import` | Day+page crumbs / outbox batches; `/clientics/import/longjob` |
| `core.schema_migrate` | One migration file per tick; `/db` + per-module on `/modules` |
| `core.module_uninstall` | Dump crumbs → DROP one table/tick → finalize; `/modules` uninstall |

## Deferred on purpose

**Calendar Re-® / bulk reparse** (`/audio/calendar/reparse`): keep the standalone progress window. Staff compare two calendar tabs side-by-side; do **not** fold into LongJobPanel overlay. Documented exception, not a debt to “finish later by default”.

## Candidate waves (do not reinvent)

**Wave B (done in product):** moderation chrome, audio_dicts suggest_descr, CDC install/uninstall, T-Invest sync, `/audio/ingest`, banks connection Sync now.

**Wave B2 (done):** yclients / bitrix / amocrm / clientics import; banks ledger sync-account; schema migrate UI (`/db` + `/modules`); module uninstall dump→drop. Still open (later): BI/summaries StreamProgress → ticks.

**Not LongJob UI (C):** edge `jobs/*`, Cron journal, MCP SSE, typical per-row CRUD / small BulkActions, **Re-®** (see Deferred).

## Backups / restore budgets (regression)

Do **not** change defaults casually:

- `DbDump::DUMP_TICK_SECONDS` = 60  
- `DbRestore` SQL 240 / inflate 120; `tick_seconds` clamp 5–240 in DbJobs  
- Replica restore crumb **8–25s** inside `BackupReplica::phaseRestore`  
- `BackupRunner::BUDGET_SEC` = 40 / light wall 240  

Verify: `/db` dump+restore, `/backups` run, `/backups/replica` pull, CLI `backups replica-pull`, `/agent/db/dump|restore`.
