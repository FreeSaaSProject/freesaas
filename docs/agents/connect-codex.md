# Connect OpenAI Codex

1. Enable `llm` + `assistant` — [connect-mcp.md](connect-mcp.md).
2. Point Codex MCP config at `https://YOUR_HOST/llm/mcp`.
3. OAuth uses **loopback** redirects (`http://127.0.0.1:PORT/callback`); FreeSaaS accepts any loopback port (RFC 8252).
4. First call: `tools/list` then `core__modules__list` or `core__doctor`.

If the host cannot fetch `chatgpt.com` client metadata, built-in CIMD fallbacks apply — [llm.md](llm.md).

Platform install/lifecycle: use CLI or `agent_token` ([agent-surfaces.md](agent-surfaces.md)), not Codex MCP alone.
