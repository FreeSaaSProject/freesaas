# Agent surfaces (auth contract)

FreeSaaS exposes **two** machine interfaces on purpose (not four). CLI and HTTP `/agent/*` are the same Surface A; session and OAuth/MCP are the same Surface B. Mixing A vs B causes 401/503 and wrong mental models.

## Surface A — Platform ops (`agent_token`)

| | |
|--|--|
| **Purpose** | Install, lifecycle, schema, health, self-update, **module repair** (ini secrets, webhook subscribe, LLM tokens) |
| **CLI** | `php bin/freesaas.php … --format=json` (same `AgentOps` as HTTP) |
| **HTTP** | `/agent/*` (catalog at `GET /agent`) |
| **Auth** | `X-FreeSaaS-Agent-Token` = `app.agent_token` in `cfg/config.ini` |
| **Virgin** | Before first staff user: `GET /agent` + `POST /agent/bootstrap` without header; bootstrap must set token |

Typical ops: `bootstrap`, `system status`, `module list/inspect/enable/install/disable/validate`, `migrate`, `db *`, `health`, `doctor`, `update *`, plus module-registered repair ops under `/agent/bots/*`, `/agent/gcal/*`, `/agent/llm/*` (`AgentOps::registerModuleOp` from enabled-module bootstrap).

Docs: [remote-api.md](remote-api.md), [install.md](install.md), [QUICKSTART.md](QUICKSTART.md), [bots.md](bots.md), [gcal.md](gcal.md), [llm.md](llm.md).

**Threat model:** root-equivalent for the instance. Do not put this token in browser JS or OAuth clients. Rotate via Settings → Agent token. Responses mask secrets (`has_*` / masked); subscribe reads tokens server-side from ini.

## Surface B — Product ops (session / OAuth / MCP)

| | |
|--|--|
| **Purpose** | Business data: clients, orders, booking, ledger, … |
| **Discovery** | `module.json` → `agent.ops[]` |
| **HTTP** | Same UI routes with `?format=json` or `Accept: application/json` |
| **In-app** | Assistant tools (`AssistantTools`) |
| **Remote agents** | MCP Streamable HTTP `POST /llm/mcp` + OAuth Bearer |
| **Auth** | Staff session **or** OAuth token; permissions via `Auth::can` |

Docs: [module-agent-api.md](module-agent-api.md), [assistant.md](assistant.md), [llm.md](llm.md), [connect-mcp.md](connect-mcp.md).

**Threat model:** acts as a **staff user** (least privilege). Writes may require assistant confirm / `ops.write` scope. Superadmin screens stay capability-gated.

## Overlap (read carefully)

| Capability | Surface A | Surface B |
|------------|-----------|-----------|
| List/inspect modules | `/agent/modules*` | `core.modules.list` / `get` (session/MCP) |
| Enable / install / migrate | **Yes** | **No** |
| Health / doctor | **Yes** | Partial (module health checks if exposed as ops) |
| bots/gcal/llm **ini secrets** + webhook subscribe | **Yes** (`/agent/bots|gcal|llm/…`) | UI twins (`/bots/settings`, …) — session SUPERADMIN |
| CRM CRUD | No | **Yes** |

MCP `tools/list` catalogs product tools (Surface B). Platform enable/install/repair stays on Surface A until an explicit admin OAuth scope exists (not shipped).

## Decision tree

```
Need to bootstrap / enable module / run migrations / update code /
repair webhook secrets / fill LLM api_key / diagnose gcal app?
  → Surface A (CLI or agent_token)

Need to call booking/clients/orders as a staff role?
  → Surface B (session cookie, or OAuth → /llm/mcp)
```

Do **not** send `X-FreeSaaS-Agent-Token` to `/llm/mcp`, and do **not** expect OAuth Bearer to authorize `/agent/modules/*/enable`.
