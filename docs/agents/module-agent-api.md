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
| `id` | yes | Dotted id, unique within the module (`module.area.action`) |
| `method` | yes | `GET` / `POST` / … |
| `path` | yes | Must match a registered route (`routes.pages` / bootstrap) |
| `permission` | recommended | Same permission key as the HTML route |
| `description` | recommended | One-line agent hint (English) |
| `side_effect` | optional | `read` or `write`. Default: GET→read, POST/PUT/PATCH/DELETE→write. Used by the [assistant harness](assistant.md): writes need user confirmation. |
| `parameters` | optional | JSON Schema object for LLM tool arguments (`type`/`properties`/`required`) |
| `assistant` | optional | Default `true`. Set `false` to hide the op from the in-system assistant tool catalog (e.g. recursive chat). |

`ModuleValidator` warns if an op path is missing from `routes.pages` / registered routes, or if `agent.ops` entries are malformed.

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

Add `agent.ops` when a module gains a stable JSON twin. References: `core` DB hub (`/db`), `audio_reports` summaries. Platform catalog of all module ops can be added later (inspect / AgentOps) without changing this shape.

Platform install/ops (`/agent/db*`, token) remain separate — see [remote-api.md](remote-api.md), [migrations.md](migrations.md), and [db-backup.md](db-backup.md).

## Helper

[`app/AgentHttp.php`](../../app/AgentHttp.php) — `wantsJson()`, `emit()`, `error()`, `opsForModule()`, `normalizeOp()`.
