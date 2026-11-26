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

## Yandex Alice connector (manual client flow)

Alice connects to the staff MCP (`/llm/mcp`) over OAuth 2.1, but its plugin form does **not** do DCR (RFC 7591) / CIMD: it asks for a pre-created client. So the agent must create the client on the server **before** the user opens the form. (Other callers may still register dynamically with redirect `https://alice.yandex.ru/plugins/oauth/callback` — host is allowed for DCR — but the Alice form itself will not.) Same checklist as portable skill [`freesaas-alisa-connect`](../../.agents/skills/freesaas-alisa-connect/SKILL.md).

Preconditions: `llm` + `assistant` enabled, `migrate run --module=llm` done, `app.base_url` is the public HTTPS host (it is the OAuth issuer), and the user has a director staff account (consents in the browser).

Checklist:

1. **Create the client first.** From the repo root on the server (secret is shown once; only its hash is stored):

```bash
php <<'PHP'
<?php
declare(strict_types=1);
require 'app/bootstrap.php';
$res = OAuthClientsRepository::create(
    'Yandex Alice',
    ['https://alice.yandex.ru/plugins/oauth/callback'],
    OAuthServer::SCOPE_READ . ' ' . OAuthServer::SCOPE_WRITE, // drop write for a read-only connector
    0
);
if (!$res['ok']) {
    fwrite(STDERR, $res['error'] . "\n");
    exit(1);
}
echo "MCP URL:            " . OAuthServer::mcpUrl() . "\n";
echo "Authorization URL:  " . OAuthServer::authorizeUrl() . "\n";
echo "Token URL:          " . OAuthServer::tokenUrl() . "\n";
echo "client_id:          " . $res['client_id'] . "\n";
echo "client_secret:      " . $res['client_secret'] . "\n";
PHP
```

2. **Give the credentials to the user in the same reply**, as one copyable block (the secret cannot be shown again). Tell them to choose the **"in header"** option where the form asks how to send client credentials (HTTP Basic; `/oauth/token` accepts header and body):

```text
MCP URL:            https://YOUR_HOST/llm/mcp
Authorization URL:  https://YOUR_HOST/oauth/authorize
Token URL:          https://YOUR_HOST/oauth/token
client_id:          fs_…
client_secret:      …
Credentials:        in header
Scopes:             ops.read ops.write
```

3. **Warn about the empty field.** The Alice form leaves a field empty (the `client_secret` one) — it must be filled in by hand, otherwise the token exchange fails with `invalid_client`. If the secret is lost, rotate it on `/llm/oauth` (**Rotate client secret**) and hand over the new one; do not create duplicate clients.
4. **Verify by reading** after the user finishes the consent: ask Alice to call `core__modules__list` (read-only). A module list = the whole chain works. Do not verify with write tools.
5. **Empty tool list?** Check in this order:
   - `assistant` is enabled (`module list`) — tools come from `agent.ops` through it.
   - The staff user who consented has the permissions behind those ops (`core.modules` for `core__modules__list`); a non-director sees only what their role allows.
   - Consent scope: write tools are hidden unless the client has `ops.write`; reconnect after changing scopes in `/llm/oauth`.
   - Bearer actually reaches PHP (`fastcgi_param HTTP_AUTHORIZATION …`, see Nginx below) — without it the consent "succeeds" but `tools/call` returns `401` and Alice only sees the anonymous catalog.
   - Redirect URI on the client is exactly `https://alice.yandex.ru/plugins/oauth/callback` (`/llm/oauth`), and the client is enabled.
   - Alice caches the tool list: disconnect and reconnect the connector after fixes.

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
