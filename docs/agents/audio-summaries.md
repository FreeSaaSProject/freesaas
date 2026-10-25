# Audio summaries (agent runbook)

Session-auth product JSON for `audio_reports` summary profiles. Convention: [`module-agent-api.md`](module-agent-api.md). Platform install/ops token API is **not** used here.

Staff day rating without a browser session: `GET /agent/audio_reports/day-report?admin=&date=` (agent_token) — see [`audio-scripts-debug.md`](audio-scripts-debug.md).

## Routes

| Op | Method | Path | Permission |
|----|--------|------|------------|
| `audio_reports.summaries.list` | GET | `/audio/summaries` | `audio_reports.view` |
| `audio_reports.summaries.get` | GET | `/audio/summaries/{id}` | `audio_reports.view` |
| `audio_reports.summaries.save` | POST | `/audio/summaries/new` or `/audio/summaries/{id}` | `audio_reports.edit` |
| `audio_reports.summaries.work` | POST (month/propose/preview/save); GET unused for month | `/audio/summaries/work` | view; edit for propose/preview/save |

Ask for JSON with `?format=json` or `Accept: application/json`.

HTML always builds the default (or `?profile=`) report for the current month. JSON list stays light unless you add `run=1`.

## Curl (session cookie)

```bash
# Login once (adjust path/fields for your install), keep jar:
curl -sS -c jar.txt -b jar.txt -X POST 'https://example.com/login' \
  -d 'login=admin&password=secret'

# List profiles
curl -sS -b jar.txt 'https://example.com/audio/summaries?format=json'

# Run report for profile 1, Sep 2026
curl -sS -b jar.txt \
  'https://example.com/audio/summaries?format=json&run=1&profile=1&year=2026&month=9'

# Get one profile
curl -sS -b jar.txt 'https://example.com/audio/summaries/1?format=json'

# Propose catalog defaults (edit)
curl -sS -b jar.txt -X POST 'https://example.com/audio/summaries/work?format=json' \
  -d 'action=propose&id_position=3&year=2026&month=9'

# Month counts (for UI animation / light poll) — POST only
curl -sS -b jar.txt -X POST 'https://example.com/audio/summaries/work?format=json' \
  -d 'action=month&id_records_summary=1&year=2026&month=9'

# Save (structured fields)
curl -sS -b jar.txt -X POST 'https://example.com/audio/summaries/new?format=json' \
  -d 'name=Report+desk&id_position=3&is_default=1&enabled=1' \
  -d 'cards[]=volume&cards[]=score&cards[]=leaders' \
  -d 'score_pass_threshold=1&benchmark_min_days=5'
```

## Report JSON sketch

`GET /audio/summaries?run=1&…&format=json` → `{ ok, profiles, report, counts }`.

`report` (from `SummaryRunner::run`) includes:

```json
{
  "year": 2026,
  "month": 9,
  "period_mode": "month",
  "period_label": "September 2026",
  "profile_id": 1,
  "profile_name": "…",
  "position_id": 3,
  "config": { "cards": ["volume", "score", "leaders"], "…": "…" },
  "labels": { "banner": "…", "card_volume": "" },
  "total_audio_hours": 12.5,
  "global_avg_score": 1.2,
  "score_pass_threshold": 1.0,
  "criteria": { "12": { "NAME": "…", "percent": 80 } },
  "scripts": { "4": { "NAME": "…", "starts": 10, "finishes": 7, "conversion": 70 } },
  "tracking": {},
  "external": [],
  "computed": [],
  "rows": {
    "101": {
      "employee_id": 101,
      "name": "Ivanov I.",
      "audio_hours": 4.2,
      "avg_score": 1.1,
      "days_detail": []
    }
  },
  "totals": { "audio_hours": 12.5, "avg_score": 1.2 }
}
```

`counts` is a flat map for UI count-up (`audio_hours`, `avg_score`, `script_conv`, `ext_*`, …).

## Work actions

| `action` | Mode | Notes |
|----------|------|-------|
| `propose` | HTML progress iframe or JSON + `stages` | Needs `id_position` |
| `preview` | same | Builds unsaved profile from POST, returns `html` (+ `report` in JSON) |
| `save` | JSON | Create/update profile |
| `month` | JSON | Light counts for month switch |

HTML progress uses `SummaryProgress::start/mark/done` and posts result to `window.parent.rsWorkDone`.

Parallel BI family (same module): `audio_desk_month` — see [`bi.md`](bi.md).
