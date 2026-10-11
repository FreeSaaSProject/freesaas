# Connect Cursor

## Coding against this repo

1. Open the FreeSaaS git root in Cursor.
2. Read [AGENTS.md](../../AGENTS.md) and [QUICKSTART.md](QUICKSTART.md).
3. Prefer CLI: `php bin/freesaas.php doctor --format=json`, `module enable`, `module create`.
4. Skills: [docs/skills/](../skills/).

## Remote instance via MCP

1. Instance must expose HTTPS `/llm/mcp` with `llm` + `assistant` enabled.
2. Add an MCP server in Cursor settings pointing at `https://YOUR_HOST/llm/mcp` (OAuth as supported by your Cursor build).
3. First tools: `core__modules__list`, `core__doctor`.
4. Do **not** put `app.agent_token` into Cursor MCP config for product tools — use OAuth. Lifecycle ops: SSH/CLI or HTTP `/agent/*` with token separately.

See [connect-mcp.md](connect-mcp.md), [agent-surfaces.md](agent-surfaces.md).
