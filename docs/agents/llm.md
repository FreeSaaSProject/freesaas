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

**Start here for copy-paste connect:** [connect-mcp.md](connect-mcp.md) (+ [connect-chatgpt.md](connect-chatgpt.md), [connect-codex.md](connect-codex.md), …). Surfaces: [agent-surfaces.md](agent-surfaces.md).

Requires `assistant` enabled for tools. UI: **LLM → OAuth / MCP** (`/llm/oauth`, SUPERADMIN + `llm.manage`).

1. Create a client; copy **client_secret** once; add exact redirect URI(s).
2. Scopes: `ops.read` (default), optional `ops.write` (write tools under OAuth consent).
3. Endpoints (also shown in UI):
   - MCP Streamable HTTP: `/llm/mcp` (`initialize` / `tools/list` without auth; `tools/call` needs Bearer)
   - Module registry in MCP: `core.modules.list` / `core.modules.get` → tool names `core__modules__list` / `core__modules__get` (needs `core.modules`). Not the same as platform `/agent/modules*` (`agent_token`).
   - Authorize / token / revoke: `/oauth/authorize`, `/oauth/token`, `/oauth/revoke`
   - Discovery: `/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`
   - OpenAPI (ChatGPT Actions fallback): `/llm/openapi.json`
   - REST: `GET /llm/api/tools`, `POST /llm/api/tools/call`

### ChatGPT Custom MCP

1. Connector URL = MCP endpoint only (`https://…/llm/mcp`). Leave Bearer empty; use **Authenticate** (not a pasted well-known URL).
2. AS metadata advertises CIMD (`client_id_metadata_document_supported`) and DCR (`/oauth/register`). ChatGPT/Codex send `client_id=https://chatgpt.com/oauth/…/client.json` — no manual client in their UI.
3. Codex native uses loopback redirects (`http://127.0.0.1:PORT/callback`); any loopback port is accepted (RFC 8252). If the host cannot fetch chatgpt.com, built-in CIMD fallbacks apply.
4. Authorize responses include `iss` (RFC 9207) so ChatGPT web can use `https://chatgpt.com/connector_platform_oauth_redirect`.
5. Mixed auth: catalog is public; calls require Bearer. `tools/call` without token → `401` + `WWW-Authenticate`.
6. Tool annotations include `readOnlyHint` / `destructiveHint`. `initialize` returns short `instructions` (≤512 chars).
7. After code deploy: `php bin/freesaas.php migrate run --module=llm` (widens `CLIENT_ID` for CIMD URLs).

Optional `public_base` in `cfg/modules/llm.ini` if Host auto-detect is wrong behind a proxy.

**Nginx + PHP-FPM:** pass the Authorization header into PHP or Bearer auth silently fails after a successful browser consent:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

Apache `.htaccess` already maps `HTTP:Authorization` → `HTTP_AUTHORIZATION`.

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
