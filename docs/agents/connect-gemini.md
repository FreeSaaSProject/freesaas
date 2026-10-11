# Connect Gemini CLI / agents

1. Boot FreeSaaS ([QUICKSTART.md](QUICKSTART.md)).
2. For **local** coding agents: point the agent at this repo + `AGENTS.md`; use `php bin/freesaas.php … --format=json`.
3. For **remote** tool use: enable `llm` + `assistant`, connect MCP URL `https://YOUR_HOST/llm/mcp` if your Gemini tooling supports Streamable HTTP MCP + OAuth ([connect-mcp.md](connect-mcp.md)).
4. Fallback without MCP: session cookie + `Accept: application/json` on `agent.ops` routes ([module-agent-api.md](module-agent-api.md)), or platform token on `/agent/*`.

First verify: `GET /agent/doctor` or MCP tool `core__doctor`.
