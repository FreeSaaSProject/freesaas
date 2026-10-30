# Script coach (audio_script_coach)

Alpha module: investigate why a staff day lost day-bonus quality stages, classify each missed session, and propose dictionary enrichments where ASR/phrasing is the cause. Soft successor to [`audio_phrases`](audio_phrases.md) for script enrichment.

## Success criterion

For every **red scoring** stage from day quality (`DayQualityReport`) and every rated session missing that stage, the run has one **verdict** with quote / time / record link:

| Verdict | Meaning | Action |
|---------|---------|--------|
| `no_audio` | no audio coverage in the window | not a dictionary issue |
| `session_gap` | session did not open / broke / start gates | script structure / start keys |
| `stem_conflict` | target lemmas present but another key eats the window | do **not** add_key; show conflict |
| `asr_near` | near sample (lemma edit) without LLM | proposal `add_key` |
| `semantic` | same intent, different wording | LLM only |
| `not_said` | phrase absent | bonus correctly withheld |

Before accept: light **what-if** (part % / color / bonus estimate). After accept: scoped **Re-®** for that `admin`+`day` (`/audio/calendar/reparse?admin=&date=`).

## Enable

```bash
php bin/freesaas.php module enable audio_script_coach --accept-risk --format=json
```

Requires: `audio`, `audio_dicts`. Soft: `llm`, `audio_reports`, `audio_pipeline`, `procedures`.

Permissions: `audio_script_coach.view`, `audio_script_coach.edit`. Starting a run and accepting proposals also need `SUPERADMIN_ACCESS`.

## Human UI

| Path | Role |
|------|------|
| `/audio/script-coach` | List runs |
| `/audio/script-coach/day?admin=&date=` | Start investigation (superadmin) |
| `/audio/script-coach/run/{id}` | Verdicts + what-if + proposals; advance / drain phases |
| `/audio/script-coach/work` | Batch tick (`action=start\|tick\|rebuild`); UI progress overlay |
| Day-report block | EventBus `audio_reports.day_report.sections` — red-stage summary + link |

## Pipeline

1. **catalog** — rating / day-bonus scripts for staff position + parts + sample keys  
2. **facts** — collections + **`quality_red`** (red scoring parts + missed sessions from DayQualityReport)  
3. **verdicts** — deterministic classification (`CoachVerdicts`); builds `asr_proposals` + `semantic_windows`  
4. **windows** — semantic leftovers only when quality_red drove the day (else legacy gap windows)  
5. **llm** — only semantic windows; merges deterministic `asr_near` proposals  
6. Human **accept/reject** → `RecordsKeysRepository` / stem-conflict gate; then Re-® this admin+day  

## Stem conflict gate (audio_dicts)

Adding a key runs `KeyStemConflict` scoped to dictionaries that share `ID_POSITION` with the target (+ globals). Detects shorter-prefix and suffix↔prefix joins that would eat (or be eaten) under stem left-to-right matching. **Blocked by default**; explicit `force` required. Does **not** change `StemRunner::findPhraseOccurrences`.

## Tables

Owned: `base_script_coach_runs`, `base_script_coach_artifacts`, `base_script_coach_proposals`.

## Agent JSON

Session auth; see `module.json` → `agent.ops`.
