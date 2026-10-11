# Connect Claude Code

## Local repo

```bash
cd /path/to/freesaas
# Claude Code reads AGENTS.md automatically in many setups
php bin/freesaas.php doctor --format=json
```

Hard rules: [AGENTS.md](../../AGENTS.md). Skills: [docs/skills/](../skills/).

## Remote MCP

1. Enable `llm` + `assistant` on the instance — [connect-mcp.md](connect-mcp.md).
2. Configure Claude Code MCP server URL: `https://YOUR_HOST/llm/mcp`.
3. Complete OAuth; call `core__doctor` / `core__modules__list`.
4. Writes to CRM go through product tools (confirm in assistant when required). Module enable/migrate: CLI or `/agent/*` token.

Surfaces: [agent-surfaces.md](agent-surfaces.md).
