# audio_phrases — Top phrases

Alpha module: daily harvest of stable N-grams from cabin transcripts, classifier UI, optional key creation.

## Enable

1. Copy `modules/audio_phrases/config.example.ini` → `cfg/modules/audio_phrases.ini` (or save from **Audio → Settings**).
2. Enable module (UI `/modules` with risk ack, or `[modules] audio_phrases=1`).
3. Migrations create `base_records_phrases` / `base_records_phrase_days`.
4. Settings UI: `/audio/settings` (superadmin) — block «Top phrases» (`with_reparse`, `cron_enabled` ↔ `/cron` job `audio_phrases.daily`, window, min instances). To stop auto-harvest: uncheck both; day «Rebuild» remains manual.

## Ops

| Op | Path |
|----|------|
| List | `GET /audio/phrases` |
| Day | `GET /audio/phrases/day?date=&admin=` (staff select = admins with harvest rows that day) |
| Status | `POST /audio/phrases/{id}/status` (`status`, optional `id_dictonary`, `create_key`, `force`; `junk` also adds to trash dict) |

Cron: `audio_phrases.daily` at `daily_at` (default 03:40), backfills `backfill_days`.

Hooks: after `audio_reports.calendar.reparse.after` harvests the day when `with_reparse=1` (default) or payload `run_phrases=1`. Manual: day page «Rebuild» / cron `audio_phrases.daily`. Link in `audio_reports.day_report.sections`.

## Garbage filter

Before N-gram extraction, lines go through `TranscriptGarbage` (`modules/audio/lib/`): dictionary trash, overlong lines, ASR token/phrase loops, **counting digit lists** (`8, 9, 10…`). Detected needles auto-fill `base_records_trashes`. N-gram slices that are pure repeats (`ну ну ну`) or **all-numeric** (`52 52 53`) are dropped.

Harvest speed: one DB transaction per admin; key catalog lemmatized once per request (filtered by position); warm `StemCache` helps most. Remaining cost is lemmatize of uncached transcript lines.

Stem parser (`StemRunner`) uses the same analyzer and also auto-fills trash.

## Bulk cleanup (`/audio/phrases`)

BulkActions: mark junk / skip / new; delete any status (harvest cleanup). Row actions (new only): Junk (also → `base_records_trashes`) / Skip; dictionary select + «+» opens editable key text then create. Linked key name under lemmas when `ID_KEY` is set.

## Position-aware dictionaries

Dict select and auto key-match use the viewed staffer's `ID_STATUS` (+ `ID_AUDIO_POSITION` peers). Empty/`0` dict `ID_POSITION` = global. Wrong-position auto-matches show a hint and keep create actions so a correct key can be added. Create «+» stays disabled until a dictionary is chosen explicitly (no prefill).

## Similar / nested keys

`RecordsKeysRepository::findOverlaps` (normalize: lower, ё→е, strip punctuation): exact, existing contains candidate, candidate contains existing. Used when creating a key from phrases («+») and from record selection (`/audio/api/dict`). UI shows overlaps and asks to confirm (`force=1`); on force, `ID_LOOKLIKE` is set to the first hit. Phrase create prefers surface `example` as key NAME (not lemmas).

## Phrase collapse / key inheritance

Harvest folds longer surface examples into a shorter sense-root when the short normalized text is contained in the long one (e.g. «Как себя чувствуете?» ← «…? Все хорошо?»). Same matched key siblings fold into the shortest example. Key match: exact lemma bag, contiguous lemma subseq, or key name inside example. List/day also coalesce the current result set and persist `ID_KEY` when an existing key sits inside the phrase.

## Status meanings

| Status | Effect |
|--------|--------|
| `new` | Needs classification; shown on day report |
| `key` | Linked to a dictionary key |
| `junk` | Hidden; sample added to `base_records_trashes` (filters transcript lines) |
| `skip` | Dimmed on day/list with **restore only** (no junk/create); harvest does not refresh day hits; restore → `new` |

Personal chatter: prefer **skip** (reversible, does not pollute trash). Use a «личные разговоры» dictionary only if you want those phrases tracked as keys.

## Future: LLM auto-classify

Soft `llm`: batch new phrases with dicts + ~30 sample keys each → JSON suggestions → human bulk confirm via `setStatusMany`. Not implemented yet.

**Script enrichment successor:** [`audio_script_coach`](audio_script_coach.md) (alpha) investigates a staff day for silent/trigger gaps and proposes keys/new dicts via LLM. Keep this module until coach proves out; do not uninstall phrases solely because coach exists.
