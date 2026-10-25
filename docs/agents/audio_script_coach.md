# Script coach (audio_script_coach)

Alpha module: investigate a staff day and propose dictionary enrichments so recognition scripts fire on new phrasing. Soft successor to [`audio_phrases`](audio_phrases.md) for script enrichment — keep phrases enabled until this approach proves out; do not delete phrases in v1.

## Enable

```bash
php bin/freesaas.php module enable audio_script_coach --accept-risk --format=json
```

Requires: `audio`, `audio_dicts`, `llm`. Soft: `audio_reports`, `audio_pipeline`.

Permissions: `audio_script_coach.view`, `audio_script_coach.edit`. Starting a run and accepting proposals also need `SUPERADMIN_ACCESS`.

## Human UI

| Path | Role |
|------|------|
| `/audio/script-coach` | List runs |
| `/audio/script-coach/day?admin=&date=` | Start investigation (superadmin) |
| `/audio/script-coach/run/{id}` | Facts + proposals; advance / drain phases |
| `/audio/script-coach/work` | Batch tick (`action=start\|tick\|rebuild`); UI progress overlay |
| Day-report button | EventBus `audio_reports.day_report.sections` |

## Batched progress

Phases are **not** drained in one HTTP request (that OOM/timeouts the host). Start creates a run and redirects to `/run/{id}?auto=1`; the browser ticks `POST /audio/script-coach/work?format=json` **one phase per request** with a DB-job-style overlay. Drain / rebuild use the same loop. Optional `stream=1` HTML iframe drains with flush marks (agents / fallback).

## Pipeline

1. **catalog** — rating scripts for staff position + parts + sample keys per dict  
2. **facts** — collection starts/finishes; `silent` / `trigger_gap` (START_GATE with no instances); incomplete miss parts  
3. **windows** — compact transcript slices (record heads / session windows)  
4. **llm** — JSON proposals: `add_key` or `add_dict_with_keys`  
5. Human **accept/reject** → `RecordsKeysRepository` / `RecordsDictsRepository`; optional attach dict to part. Then Re-® the day manually.

## Tables

Owned: `base_script_coach_runs`, `base_script_coach_artifacts`, `base_script_coach_proposals`.

## Agent JSON

Session auth; see `module.json` → `agent.ops`.
