# Connect ChatGPT Custom MCP

1. Enable `llm` + `assistant`; migrate `llm` — [connect-mcp.md](connect-mcp.md).
2. ChatGPT → Settings → Apps / Connectors → add MCP.
3. **Connector URL:** `https://YOUR_HOST/llm/mcp` only. Leave Bearer empty; use **Authenticate**.
4. Complete FreeSaaS OAuth (CIMD/DCR — usually no manual client).
5. Smoke: ask the model to list modules (`core__modules__list`) or run doctor (`core__doctor`).

Details / proxy notes: [llm.md](llm.md) § ChatGPT Custom MCP.
