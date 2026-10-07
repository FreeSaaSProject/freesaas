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

## OAuth + MCP (external GPT / agents)

Requires `assistant` enabled for tools. UI: **LLM → OAuth / MCP** (`/llm/oauth`, SUPERADMIN + `llm.manage`).

1. Create a client; copy **client_secret** once; add exact redirect URI(s).
2. Scopes: `ops.read` (default), optional `ops.write` (write tools under OAuth consent).
3. Endpoints (also shown in UI):
   - MCP Streamable HTTP: `/llm/mcp` (`initialize` / `tools/list` without auth; `tools/call` needs Bearer)
   - Authorize / token / revoke: `/oauth/authorize`, `/oauth/token`, `/oauth/revoke`
   - Discovery: `/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`
   - OpenAPI (ChatGPT Actions fallback): `/llm/openapi.json`
   - REST: `GET /llm/api/tools`, `POST /llm/api/tools/call`

### ChatGPT Custom MCP

1. Connector URL = MCP endpoint (`https://…/llm/mcp`). Transport is streamable HTTP (POST JSON-RPC; GET with `Accept: text/event-stream` returns an empty SSE listen stream).
2. ChatGPT discovers OAuth via well-known metadata; staff completes PKCE consent.
3. Mixed auth: catalog is public; calls require Bearer. `tools/call` without token → `401` + `WWW-Authenticate` pointing at protected-resource metadata.
4. Tool annotations include `readOnlyHint` / `destructiveHint`. `initialize` returns short `instructions` (≤512 chars).
5. Browser `Origin` allowlist: `chatgpt.com`, `openai.com`, this host (and localhost).

Optional `public_base` in `cfg/modules/llm.ini` if Host auto-detect is wrong behind a proxy.

Staff flow: GPT opens authorize → FreeSaaS login → Allow → code → token → Bearer calls as that staff user (`Auth::runAs` + module permissions).

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
