# Listing FreeSaaS in MCP catalogs

FreeSaaS exposes **per-instance** MCP (not a global SaaS URL):

- **Transport:** MCP Streamable HTTP  
- **URL template:** `https://<customer-host>/llm/mcp`  
- **Auth:** OAuth 2.1 (staff consent); `tools/list` public; `tools/call` Bearer  
- **Discovery:** `/.well-known/oauth-authorization-server`, `oauth-protected-resource`  
- **Requires modules:** `llm`, `assistant`

## Checklist before submitting a listing

1. Public HTTPS demo or documented self-host path ([QUICKSTART.md](QUICKSTART.md), Docker Compose).
2. README tagline + [connect-mcp.md](connect-mcp.md) linked from the listing.
3. Example first tools: `core__modules__list`, `core__doctor`, `core__health__detail`.
4. Clear note: **lifecycle** (enable/migrate) uses `agent_token` / CLI — [agent-surfaces.md](agent-surfaces.md).
5. License: AGPL-3.0-or-later (+ commercial option).

## Suggested listing blurb

> FreeSaaS — open-source business OS operated by AI agents. Connect MCP to your self-hosted instance for CRM/finance/booking tools; install and repair via CLI or `/agent/*`.

Update this file with concrete catalog URLs once a demo host is stable.
