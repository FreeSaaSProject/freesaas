# Module agent API (product JSON)

Product modules expose machine-readable ops for coding agents **on the same HTTP routes as humans**, returning JSON when asked.

This is **not** platform [`/agent/*`](remote-api.md) (`AgentOps` + `app.agent_token` = install/ops only).

## Convention

1. Declare ops in [`module.json`](../../AGENTS.md) under `agent.ops[]`.
2. Implement on existing module routes (or a dedicated sibling like `/…/work`).
3. Gate with the same `Auth::can(permission)` as the HTML UI (session cookie).
4. Detect JSON via `AgentHttp::wantsJson()` (`?format=json` or `Accept: application/json`).
5. Respond with `AgentHttp::emit([...])` / `AgentHttp::error(...)`.

### `module.json` shape

```json
"agent": {
  "docs": "docs/agents/certificates.md",
  "ops": [
    {
      "id": "audio_reports.summaries.list",
      "method": "GET",
      "path": "/audio/summaries",
      "permission": "audio_reports.view",
      "description": "List summary profiles; add run=1&profile=&year=&month= for a report"
    }
  ]
}
```

| Field | Required | Notes |
|-------|----------|--------|
| `docs` | optional | Path under `docs/agents/*.md` (allowlisted). Staff help / MCP `core.help.*` serves this file — single source of truth for «how do I…»; keep **Human UI** section updated with the module. |
| `id` | yes (per op) | Dotted id, unique within the module (`module.area.action`) |
| `method` | yes | `GET` / `POST` / … |
| `path` | yes | Must match a registered route (`routes.pages` / bootstrap) |
| `permission` | recommended | Same permission key as the HTML route |
| `description` | recommended | One-line agent hint (English) |
| `side_effect` | optional | `read` or `write`. Default: GET→read, POST/PUT/PATCH/DELETE→write. Used by the [assistant harness](assistant.md): writes need user confirmation. |
| `parameters` | optional | JSON Schema object for LLM tool arguments (`type`/`properties`/`required`) |
| `assistant` | optional | Default `true`. Set `false` to hide the op from the in-system assistant tool catalog (e.g. recursive chat). |

`ModuleValidator` warns if an op path is missing from `routes.pages` / registered routes, if `agent.ops` entries are malformed, or if `agent.docs` points outside `docs/agents/` / missing file.

In-system assistant (`modules/assistant`): tools are **auto-built** from enabled modules’ `agent.ops` and invoked in-process via `AgentHttp::capture` + the same route handler (no HTTP loopback, no per-module register). Catalog helper: `AgentHttp::allOps()`.

## Client example

```bash
# After browser login (session cookie) or curl -c/-b jar:
curl -sS -b jar.txt 'https://example.com/audio/summaries?format=json'
curl -sS -b jar.txt -H 'Accept: application/json' \
  'https://example.com/audio/summaries?run=1&profile=1&year=2026&month=9'
```

Response envelope:

```json
{ "ok": true, "…": "op-specific fields" }
```

Errors: `{ "ok": false, "error": "…" }` with HTTP 4xx/5xx.

## Rollout

All product modules declare `agent.ops` (session JSON on the same routes as the UI). `comments` has an empty list — no own HTTP routes (thread embeds on core news via EventBus). Worker/pipeline endpoints may set `"assistant": false` so they stay out of the in-system assistant catalog but remain callable via MCP/session JSON.

Platform install/ops (`/agent/db*`, token) remain separate — see [remote-api.md](remote-api.md), [migrations.md](migrations.md), and [db-backup.md](db-backup.md).

**Module catalog for MCP/assistant:** `core.modules.list` (`GET /modules?format=json`) and `core.modules.get` (`GET /modules/{id}?format=json`) — same `ModuleCatalog` data as platform `module.list` / `module.inspect`, but session/`core.modules` auth so they appear in `/llm/mcp` `tools/list`. `get` includes `agent_ops[]` with `mcp_name` for that module’s tools.

**Storefront diagnose example:** `sites.status` → `GET /sites/status?format=json` (hosts, soft deps, booking, `issues[]`; product overlays via EventBus `sites.diagnose`). Runbook: [sites.md](sites.md).

## Update ops are patches

`*.update` over JSON must keep fields the client did not send (checkboxes included) — the assistant passes only what changed. Merge the stored row under `$_POST` before validation (see `VacationsRepository::patchPost`, `workweeks.update`).

## Relations (multi-step plans)

Every write the assistant queues in one turn becomes a numbered step of **one plan** (`base_assistant_pending.PLAN_ID`); one Confirm runs all steps in order, stops at the first failure and cancels the rest. In capability mode (Max groups) the confirmer must hold every step’s permission before anything runs.

Cross-module consequences are declared by the **dependent** module under `agent.relations[]` — no per-case code:

```json
"relations": [
  {
    "id": "workweeks.vacation_shifts",
    "when": ["vacations.create", "vacations.update"],
    "from": "vacations.get",
    "check": "workweeks.list",
    "args": { "user_id": "{user_id}", "date_from": "{dt_start|date}", "date_to": "{dt_end|date}", "page": "all" },
    "suggest": ["workweeks.delete"],
    "hint": "workweeks.relation.vacation_shifts"
  }
]
```

| Field | Notes |
|-------|-------|
| `when` | Write op ids that trigger the check when they are queued |
| `if` | Optional conditions on write args: literal (`{"enabled": "0"}`), `"present"`, or `"changed"` (differs from the `from` row) |
| `from` | Optional read op (called with the write args) whose `from_key` (default `item`) fills missing placeholders — e.g. `user_id` of an updated row. Old values stay reachable as `{from.field}` |
| `check` | Read op run with rendered `args`: `{var}`, `{from.var}`, `{today}`, filter `\|date` (→ `YYYY-MM-DD`) |
| `suggest` | Write ops the model should add as plan steps for affected rows |
| `fields` | Optional row keys shown to the model (`id` always kept) |
| `max_rows` | Above this (default 15) rows are only warned about — no extra plan steps |
| `hint` | i18n key appended to the system prompt and the tool result |
| `notice` | i18n key (`{n}` = rows) added to the plan preview so humans see the consequence |

The harness (`AssistantRelations`) runs checks **before** the step is stored, adds `notice` lines (and «not checked» notes) to the step preview, attaches `related` (count, slim rows, available/unavailable suggestions) to the tool result and gives the model one more round to add steps. Rows already reported in the same turn are not repeated (vacation → shift → events chains).

Disabled modules never break a plan: relations of a disabled module are not loaded; a `check` / `suggest` op outside the channel catalog becomes a note («not checked — check manually»); a placeholder without a value (e.g. `from` op not allowed in the channel) becomes a `check_skipped` note instead of a silent skip. `ModuleValidator` checks relation op ids against every module on disk (disabled is fine, typos warn). Tests: `bin/check-assistant-relations.php`.

Absence family shipped: `workweeks.vacation_shifts`, `workweeks.user_disabled_shifts`, `calendar.{vacation,shift_removed,shift_reassigned,user_disabled}_events`, `orders.{vacation,shift_removed,shift_reassigned,user_disabled}_orders` — client events/orders are reassigned to someone on shift or listed for manual handling, never cancelled.

Role-scoped reads may also honor `AgentHttp::readScopeUnrestricted()` (true only inside `AgentHttp::withUnrestrictedRead()` on GET) — used by group drafts when `assistant_groups.group_read_unrestricted=1`. Never apply it to writes.

## Delete ops

Product delete (edit `action=delete` or BulkActions `delete`) requires:

1. Staff permission `*.delete` (superadmin bypasses via `Auth::can`).
2. No blocking related rows (`core.bulk.check_item`).
3. When the client asks for JSON (`AgentHttp::wantsJson()`), platform flag **`app.agent_allow_delete=1`** (Settings → Agent). Default is off — agents get `403` with `core.entity.agent_delete_denied`.

Declare delete ops with `permission: module.delete`, `side_effect: write`, and `POST` to the same edit path as humans (`action=delete`).

## Helper

[`app/AgentHttp.php`](../../app/AgentHttp.php) — `wantsJson()`, `allowsDelete()`, `emit()`, `error()`, `opsForModule()`, `normalizeOp()`, `withUnrestrictedRead()` / `readScopeUnrestricted()`.
