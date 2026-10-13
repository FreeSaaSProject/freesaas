# Max — in-system assistant (tool harness)

Product module `assistant` (UI name **Max** / **Макс**): conversational agent that calls product `agent.ops` automatically. Channels: UI `/assistant` and staff Telegram/Max-messenger bot («Ask Max»). Not platform `/agent/*`.

## Enable

```bash
php bin/freesaas.php module enable llm --format=json
php bin/freesaas.php module enable assistant --format=json
# optional bot channel:
php bin/freesaas.php module enable bots --format=json
```

Requires a ready LLM profile (`/llm/tokens`). Grant staff `assistant.use` plus underlying tool permissions (`procedures.view`, `staff_reports.view`, …).

## Behaviour

- **Tool catalog** is built automatically from enabled modules’ `module.json` → `agent.ops` (filtered by `Auth::can` and route presence). No per-module `AssistantTools::register`.
- **Invoke** runs the same page handler in-process with `format=json` / `AgentHttp::capture` (no HTTP loopback).
- **Read tools** run immediately inside `AssistantHarness`.
- **Write tools** never auto-run: harness stores `base_assistant_pending` and returns `pending_actions[]`. UI buttons or bot callbacks confirm/cancel (TTL 15 minutes).

Opt out of the assistant tool list with `"assistant": false` on an op (used for `assistant.chat` / `assistant.confirm` / `llm.chat` to avoid recursion).

## UI

- `GET /assistant` — chat page
- `POST /assistant/chat` — JSON body `{ "messages":[{"role":"user","content":"…"}] }`
- `POST /assistant/confirm` — `{ "token":"…", "action":"confirm"|"cancel" }`

```bash
curl -sS -b jar.txt -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"messages":[{"role":"user","content":"How were things yesterday?"}]}' \
  'https://example.com/assistant/chat'
```

## Bot (staff)

With `bots` enabled, plugin `assistant` handles free-text on the staff webhook. Confirm/cancel via `assistant:ok:{token}` / `assistant:no:{token}`. Auth: `Auth::runAs(staff_id)`.

## How-to («как сделать»)

Staff how-to answers use **`core.help.search`** / **`core.help.get`**, which read `module.json` → `agent.docs` → `docs/agents/*.md` (permission-filtered). Keep the **Human UI** section in that runbook updated — do not duplicate guides in i18n. Same tools appear on MCP `/llm/mcp`.

## Adding a tool

1. Add `agent.ops[]` entry (path, permission, description; optional `side_effect`, `parameters` JSON Schema).
2. Ensure the page responds with `AgentHttp::emit` / `error` when `AgentHttp::wantsJson()`.
3. Done — assistant picks it up on next request.

See [module-agent-api.md](module-agent-api.md).

## Verify

```bash
php bin/freesaas.php module validate assistant
php bin/freesaas.php locale validate
php bin/freesaas.php health --module=assistant
php bin/smoke.php
```
