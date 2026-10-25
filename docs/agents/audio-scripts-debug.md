# Audio scripts remote debug (agent_token)

Shared-hosting agents cannot rely on a staff browser session to diagnose why script triggers fire (or not). Use **Surface A** `/agent/*` with `X-FreeSaaS-Agent-Token` (same secret as bootstrap / doctor). Product UI JSON (`/audio/…?format=json`) still needs a session — these endpoints do not.

**When to use:** prod vs local drift, “same scripts but no collections”, missing key hits, day reparse after editing parts/dicts.

**Modules:** `audio_dicts` (defs), `audio_pipeline` (records / instances / collections / reparse), `audio_reports` (day rating report).

## Auth

```bash
export TOKEN='…'   # app.agent_token — never put in query string
export BASE='https://example.com'
H=(-H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Accept: application/json")
```

Catalog: `curl -sS "${H[@]}" "$BASE/agent" | jq '.ops[]|select(.path|test("audio_"))'`

## Quick triage (admin + day)

```bash
# 1) Counts + script fingerprint (default probe script=32, override ?script=)
curl -sS "${H[@]}" "$BASE/agent/audio_pipeline/day-diag?admin=58&date=2026-10-16"

# 2) Day rating rows (UI twin, no HTML)
curl -sS "${H[@]}" "$BASE/agent/audio_reports/day-report?admin=58&date=2026-10-16"

# 3) Script tree on this instance
curl -sS "${H[@]}" "$BASE/agent/audio_dicts/script?id=32"
```

Compare `script_probe_hash` / `fingerprint` across hosts. **Different hash ⇒ DB part defs differ** (FTP of PHP alone will not sync scripts). Fewer `collections_total` with same records/transcriptions ⇒ collect/stem or defs, not ingest.

## Scripts & dictionaries (`audio_dicts`)

| Method | Path | Notes |
|--------|------|--------|
| GET | `/agent/audio_dicts/scripts` | Root list; `q=`, `include_disabled=1` |
| GET | `/agent/audio_dicts/script?id=` | Root + **all parts** + `fingerprint` |
| POST | `/agent/audio_dicts/script` | Full save: JSON `{id, confirm:1, meta{}, parts[]}`. **confirm required** |
| POST | `/agent/audio_dicts/script/part` | Upsert one part: `{script_id, confirm:1, part:{…}}` (`id` omit = create) |
| POST | `/agent/audio_dicts/script/part/delete` | `{script_id, part_id, confirm:1}` |
| GET | `/agent/audio_dicts/dicts` | Dictionaries; `q=`, `include_disabled=1` |
| GET | `/agent/audio_dicts/dict?id=` | One dict + `keys_total` |
| GET | `/agent/audio_dicts/keys?dict=` | Keys page; `q=`, `limit=`, `offset=` |

Part payload uses the same fields as UI/normalizePart (`name`, `break_flag`, `timeout`, `break_gap`, `finish`, `start_gate`, `collection_killer`, `dict_ids` / `ids_dictonarys`, `score`, `score_rate`, `kpi`, `cond_type`, `cond_value`, `roles`, …). Prefer **part** upsert over full tree replace unless you intentionally rewrite the script.

```bash
# Example: bump TIMEOUT on part 123 of script 32
curl -sS -X POST "${H[@]}" -H "Content-Type: application/json" \
  "$BASE/agent/audio_dicts/script/part" \
  -d '{"script_id":32,"confirm":1,"part":{"id":123,"timeout":180}}'
```

After definition changes, **reparse the day** (below). Stem/collect do not auto-rebuild historical days on `audio_dicts.changed`.

## Records, instances, collections (`audio_pipeline`)

| Method | Path | Notes |
|--------|------|--------|
| GET | `/agent/audio_pipeline/day-diag` | Aggregates; `?script=` fingerprint probe |
| GET | `/agent/audio_pipeline/day-records` | List records; `transcription=1` truncates to 2k chars |
| GET | `/agent/audio_pipeline/record?id=` | Full transcription + instances |
| GET | `/agent/audio_pipeline/instances` | **Вхождения** for admin+date; `record=`, `key=`; includes `by_key` histogram |
| GET | `/agent/audio_pipeline/collections` | Collections for admin+date; `script=` filter; `datas_count` |
| GET | `/agent/audio_pipeline/collection?id=` | One collection + `collections_datas` hits (part/key names) |
| POST | `/agent/audio_pipeline/day-reparse` | Reset+stem+collect for **one admin+day**; `{admin,date,confirm:1}` optional `reset=0`, `time_budget_sec` (30–300, default 180) |

```bash
# Who hit which keys that day?
curl -sS "${H[@]}" "$BASE/agent/audio_pipeline/instances?admin=58&date=2026-10-16&limit=50"

# Collections for М: Приём
curl -sS "${H[@]}" "$BASE/agent/audio_pipeline/collections?admin=58&date=2026-10-16&script=32"

# Zoom one collection → part hits
curl -sS "${H[@]}" "$BASE/agent/audio_pipeline/collection?id=4466459"

# Reparse one staff day after editing script/dicts (blocking, holds stem lock)
curl -sS -X POST "${H[@]}" -H "Content-Type: application/json" \
  "$BASE/agent/audio_pipeline/day-reparse" \
  -d '{"admin":58,"date":"2026-10-16","confirm":1,"time_budget_sec":180}'
```

`day-reparse` returns `423` if stem lock busy; `message=reparse_partial_pending` if stem budget ran out — call again or raise `time_budget_sec`. Response `after` repeats collection counts for a quick before/after.

## Day report (`audio_reports`)

| Method | Path | Notes |
|--------|------|--------|
| GET | `/agent/audio_reports/day-report?admin=&date=` | Same builder as `/audio/calendar/day-report` (no `sections_html`) |

## Suggested debug loop

1. `day-diag` on **local** and **prod** → compare `module_versions`, `records`, `instances`, `collections_total`, `script_probe_hash`.
2. If hashes differ → `script?id=` both sides; align parts (`script/part` or pack import UI); then `day-reparse`.
3. If hashes match but instances low → check ASR/transcription (`day-records&transcription=1`, `record?id=`).
4. If instances OK but collections low → `instances&by_key`, `collections`, `collection?id=` datas; check part `dict_ids`, gates, BREAK/TIMEOUT/BREAK_GAP.
5. Confirm UI: `day-report` then calendar cell.

## Safety

- All **writes** require `confirm=1` (or `true` / `yes`).
- `day-reparse` resets stem/collections for that admin+day window (reviews preserved like UI Re-®).
- Do not commit `agent_token`. Rotate if pasted into chat logs.
- Prefer scoped `admin`+`date` filters — never dump unbounded transcription tables.

## Related

- [`audio_dicts.md`](audio_dicts.md) — human UI + session `agent.ops`
- [`audio_pipeline.md`](audio_pipeline.md) — workers / cron / stem jobs
- [`audio-summaries.md`](audio-summaries.md) — summary profiles
- [`remote-api.md`](remote-api.md) — Surface A auth
- [`agent-surfaces.md`](agent-surfaces.md) — token vs session vs MCP
