# Assistant — in-system AI (tool harness)

Product module `assistant`: conversational agent that calls product `agent.ops` automatically. Channels: UI `/assistant`, floating single-letter FAB on admin pages, and staff Telegram/Max-messenger bot («Ask {name}»). Not platform `/agent/*`.

**Persona name:** configurable under **System settings** (`/settings`, field «Assistant name»). Default **Макс**. Stored in `cfg/modules/assistant.ini` (`[assistant] name=`). The system prompt, chat UI, rail chat item, FAB letter, and bot cabinet button use this name.

**Floating FAB:** one letter (first of the persona name) bottom-right on admin pages (not on `/assistant/*`). Opens a drawer that shares `AssistantDialog` history with the full chat and bot. Each FAB message sends `mode=page` + `page_context` JSON; the harness shrinks tools to the current module + `core.help.*` and appends the screen JSON to the system prompt. When the catalog is truncated, virtual meta-tools `assistant.tools.modules` → `list` → `unlock` let the model browse the full permitted catalog and unlock other modules mid-turn (still `Auth::can` — not a permission bypass).

**Personal prefs** (`/assistant/prefs`, every signed-in staff user): hide FAB everywhere (`assistant.fab_off`) or per path (`assistant.fab_hidden_paths`) via `base_user_prefs`. From the drawer: **Hide on this page** → `POST /assistant/fab/hide`. Full `/assistant` stays reachable by URL.

## Enable

```bash
php bin/freesaas.php module enable llm --format=json
php bin/freesaas.php module enable assistant --format=json
# optional bot channel:
php bin/freesaas.php module enable bots --format=json
```

Requires a ready LLM profile (`/llm/tokens`, `llm.manage` + SUPERADMIN). Assistant chat (`/assistant`) is available to every signed-in staff user — no `assistant.use` permission. Tool calls still respect each op’s own permissions (`procedures.view`, `staff_reports.view`, …).

## Behaviour

- **Tool catalog** is built automatically from enabled modules’ `module.json` → `agent.ops` (filtered by `Auth::can` and route presence). No per-module `AssistantTools::register`.
- **Invoke** runs the same page handler in-process with `format=json` / `AgentHttp::capture` (no HTTP loopback).
- **Read tools** run immediately inside `AssistantHarness`.
- **Write tools** never auto-run: harness stores `base_assistant_pending` and returns `pending_actions[]`. UI buttons or bot callbacks confirm/cancel (TTL 15 minutes). Draft detail (who/what changes) comes from EventBus `assistant.write.preview` — owning modules append `lines` / set `handled` (`*AssistantPreview` in workweeks, calendar, payments, clients, ledger, catalog, procedures); harness only formats a generic args fallback.
- **System prompt hints** for product tools: EventBus `assistant.system.hints` (`op_ids`, `group_mode`, append to `hints[]`). Owning modules (finance, bi, payments, workweeks, calendar, wellness, …) register their own copy — assistant does not hardcode other modules’ domain rules.
- **Tool progress titles** (`humanOpTitle`): place from owning module — `{mod}.assistant.area.{area}` → `{mod}.assistant.tool_title` → `{mod}.module.name`. Generic action verbs stay in `assistant.tool.act.*`.

Opt out of the assistant tool list with `"assistant": false` on an op (used for `assistant.chat` / `assistant.confirm` / `llm.chat` to avoid recursion).

## UI

- `GET /assistant` — full chat page (all tools)
- `POST /assistant/chat` — JSON `{ "message":"…" }` or `messages[]`; FAB adds `mode:"page"` + `page_context` (path/module/title/breadcrumbs/…)
- `POST /assistant/confirm` — `{ "token":"…", "action":"confirm"|"cancel" }`
- `POST /assistant/feedback` — rate a reply `{ rating:"good"|"bad", reasons:[], comment, messages:[{role,content}] }`; stored as JSONL under `var/assistant/feedback/YYYY-MM.jsonl`
- `GET`/`POST` `/assistant/prefs` — personal FAB prefs (all staff)
- `POST /assistant/fab/hide` — `{ "path":"/…" }` hide FAB on that path for the current user
- `GET`/`POST` `/assistant/eval` — **SUPERADMIN** hybrid eval (deterministic + live LLM); JSON on `Accept: application/json`
- Persona name: **Settings** (`/settings`) → Assistant name (SUPERADMIN / `core.settings`)
- Layout stamps `#freesaas-page-context` JSON and injects FAB via `core.layout.gate` → `floating`

**Shadow UI (browser only):** when a write is queued, `POST /assistant/chat` may also return `ui_actions[]` (`navigate` / `fill` / `highlight`) derived from the first pending op’s path + args (`AssistantUiActions`). The FAB auto-opens the edit screen and cosmetically fills matching `name=` fields so staff can see the draft; **Confirm still runs the API** (no HTML form submit). After Confirm, `ui_actions: [{type:"reload"}]` refreshes the page. Full chat does not auto-navigate — pending card has «Show on screen». Messengers ignore this channel.

Outgoing assistant text runs through `AssistantLinks::absolutize`: relative `/paths` become absolute admin URLs, and bare module roots with a single menu/route screen (e.g. `/finance` → `/finance/balance`) are rewritten so messengers and the chat UI do not get 404s.

Link discipline: the system prompt is **tool-scoped** — no hardcoded product modules. Module hints (ФОТ, finance, BI `open_url`, wellness food chain) are appended only when the matching tool is in **this** user's catalog. If the final reply still invents denied admin URLs, the harness sends one **link_repair** turn (lists bad hrefs + allowed tool paths/`open_url`) and asks the model to rewrite. Then `AssistantLinks::filterUnauthorized` strips any leftovers so hallucinated links never reach the user.

## Eval (regression suite)

Universal cases (no tenant-specific staff ids): tool-allowlist personas + graders for link/prompt bugs (`/finance` vs `/finance/balance`, `/bi/shares` vs `/bi/run`, no finance links without the tool) and shift-name resolution (`live.workweeks_resolve_names`: must CALL `workweeks.day`/`list` before asking surname/ID). Live shift cases use **fixture packs** under `modules/assistant/eval/fixtures/`: `workweeks_resolve_names.json` (one Dasha / one Tanya) and `workweeks_resolve_names_two_tanyas.json` (full day roster, two Tanyas). Writes are stubbed pending and do not touch the DB. Add `fixture` on a case + JSON under `eval/fixtures/` for more packs. When `link_repair` actually fired, the UI marks the card yellow (**repaired**) vs green clean pass.

```bash
php bin/freesaas.php assistant eval --live=0 --format=json   # deterministic only
php bin/freesaas.php assistant eval --format=json            # + live LLM
```

UI: **Assistant → Assistant eval** (`/assistant/eval`, SUPERADMIN). Stepped progress (one case per `POST action=run_one`); panel shows progress bar, question/answer cards, running stats. Last run is saved per LLM profile under `var/assistant/eval/{profile}.json` (not DB) and restored on entry — **Run all** or **Retry fails** / per-card **Retry**. Missing modules or LLM config → `skip`, not `fail`.

```bash
curl -sS -b jar.txt -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"messages":[{"role":"user","content":"How were things yesterday?"}]}' \
  'https://example.com/assistant/chat'
```

## Bot (staff)

With `bots` enabled, plugin `assistant` handles free-text on the staff webhook. Confirm/cancel via `assistant:ok:{token}` / `assistant:no:{token}`. Auth: `Auth::runAs(staff_id)`.

**Max groups:** optional module [`assistant_groups`](assistant-groups.md) — mention → draft with `CONFIRM_MODE=capability` → manager confirm (button or text). Private DM flow unchanged (`CONFIRM_MODE=self`).

## How-to («как сделать»)

Staff how-to answers use **`core.help.search`** / **`core.help.get`**, which read `module.json` → `agent.docs` → `docs/agents/*.md` (permission-filtered). Keep the **Human UI** section in that runbook updated — do not duplicate guides in i18n. Same tools appear on MCP `/llm/mcp`.

## Updates (self-update)

When module `updates` is enabled, the assistant sees `updates.status` / `updates.check` / `updates.apply` (and settings) if the staff user has `updates.view` / `updates.apply`. **Apply** also requires `SUPERADMIN_ACCESS` (same as the Updates UI). Flow: check → summarize → apply only on explicit ask (write tool → Confirm/Cancel). See [update.md](update.md).

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
php bin/freesaas.php assistant eval --live=0 --format=json
php bin/smoke.php
```
