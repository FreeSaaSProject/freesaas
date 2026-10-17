# Connect FreeSaaS (MCP)

Copy-paste entry for external agents. Auth model: [agent-surfaces.md](agent-surfaces.md). Deep dive: [llm.md](llm.md).

This page is the **staff** MCP (`/llm/mcp`, OAuth). Client agents that book on behalf of customers use the public storefront server `/mcp` (no auth): [client-mcp.md](client-mcp.md).

## Prerequisites

```bash
php bin/freesaas.php module enable llm --accept-risk --format=json
php bin/freesaas.php module enable assistant --accept-risk --format=json
php bin/freesaas.php migrate run --module=llm --format=json
```

Director staff account (can consent OAuth). Optional: create OAuth client under **LLM → OAuth / MCP** (`/llm/oauth`). ChatGPT/Codex often use DCR/CIMD — no manual client.

## Endpoints

| What | URL |
|------|-----|
| MCP (Streamable HTTP) | `https://YOUR_HOST/llm/mcp` |
| OAuth authorize | `https://YOUR_HOST/oauth/authorize` |
| Well-known AS | `https://YOUR_HOST/.well-known/oauth-authorization-server` |
| Tools REST | `https://YOUR_HOST/llm/api/tools` |
| OpenAPI island | `https://YOUR_HOST/llm/openapi.json` |

`tools/list` works without Bearer; `tools/call` needs OAuth Bearer (staff permissions).

## First successful call (≈5 minutes)

1. Connector / MCP URL = **only** `https://YOUR_HOST/llm/mcp` (not the well-known URL).
2. Authenticate in the client → FreeSaaS login → Allow.
3. Call read tools, e.g. `core__modules__list`, `core__doctor`, `core__health__detail`.
4. Platform **enable/migrate/bootstrap** still use CLI or `/agent/*` + `X-FreeSaaS-Agent-Token` — not MCP.

## Per-environment guides

- [connect-chatgpt.md](connect-chatgpt.md) / Codex notes in [llm.md](llm.md)
- [connect-cursor.md](connect-cursor.md)
- [connect-claude-code.md](connect-claude-code.md)
- [connect-codex.md](connect-codex.md)
- [connect-gemini.md](connect-gemini.md)

## Nginx

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

Apache `.htaccess` already maps Authorization.
