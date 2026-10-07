# LLM engine and audio studies

Product modules: `llm` (shared client + token UI) and `audio_llm` (studies over transcripts).

## Enable

```bash
php bin/freesaas.php module enable llm --format=json
php bin/freesaas.php module enable audio_llm --format=json
# copies example → cfg/modules/llm.ini if missing; run migrations for audio_llm
```

Or Modules UI. Secrets live only in `cfg/modules/llm.ini` (gitignored). Do not commit live API keys.

## Tokens

1. Open `/llm/tokens` (SUPERADMIN + `llm.manage`).
2. Add a profile: API key, base URL (default DeepSeek chat completions), model.
3. Mark one profile as default.

Health check `llm.profile` warns when no enabled profile has a key.

## Chat (agents / bots)

```bash
curl -sS -b jar.txt -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"messages":[{"role":"user","content":"ping"}],"json":false}' \
  'https://example.com/llm/chat'
```

Permission: `llm.use`. Optional body fields: `profile`, `model`, `temperature`, `max_tokens`, `json`.

For tool-calling (product CRM assistant), use module [`assistant`](assistant.md) (`LlmClient::chatWithTools` + `AssistantHarness`), not raw `/llm/chat`.

## Studies UI

Menu: Audio analytics → LLM studies (`/audio/llm-studies`).

- Tabs: regular / cyclic / spawned
- Create: employee+range, record IDs, or cyclic template (trigger product + amounts)
- Process queue on study page or `GET /audio/llm-studies/work?study_id=&limit=&format=json`

Agent ops: see `modules/audio_llm/module.json` → `agent.ops`.

## Cyclic + staff reports

1. Create a cyclic template (enabled) with `TRIGGER_GOOD_ID` and ± amounts.
2. On staff report position profile, enable **Run cyclic LLM studies**.
3. Generate day report (`/staff-reports/generate` or calendar Re-®). After the report comment is published, `staff_reports.day.after_publish` runs `CyclicDayRunner`: spawn/process child study, write `PRICE_OFFER` contract comments under the day report.

Requires `audio_llm` + `comments` + configured LLM token.

## Verify

```bash
php bin/freesaas.php module validate llm
php bin/freesaas.php module validate audio_llm
php bin/freesaas.php locale validate
php bin/freesaas.php health --module=llm
php bin/smoke.php
```
