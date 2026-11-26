---
name: freesaas-alisa-connect
description: >-
  Connect Yandex Alice to a FreeSaaS instance's staff MCP (/llm/mcp)
  over OAuth 2.1 when the Alice plugin form needs a pre-created client (no DCR).
  Use when the user wants to connect Alice / Yandex Alice / alice.yandex.ru
  connector or plugin to FreeSaaS, gets invalid_client or an empty tool list
  after connecting, or asks for client_id/client_secret for the Alice form.
  Requires a working install with llm + assistant enabled.
license: AGPL-3.0-or-later
metadata:
  author: FreeSaaSProject
  version: "1.0.0"
  homepage: https://freesaas.ru
  repository: https://github.com/FreeSaaSProject/freesaas
---

# FreeSaaS ↔ Yandex Alice connector (manual client)

Alice talks to the **staff** MCP `https://YOUR_HOST/llm/mcp` over OAuth 2.1. Its plugin form cannot do DCR (RFC 7591) or CIMD and asks for a ready client — so the client must exist **before** the user opens the form.

## Preconditions

- Bootstrapped instance, CLI access on the server (repo root).
- `llm` + `assistant` enabled and `php bin/freesaas.php migrate run --module=llm` done.
- `app.base_url` is the public **HTTPS** host (it is the OAuth issuer; `localhost` here means wrong URLs in the credentials block).
- The user has a director staff account (consents in the browser).

If something is missing, use skills `freesaas-deploy` / `freesaas-module-config` first.

## Workflow

1. **Create the client before the form.** Redirect URI must be exactly `https://alice.yandex.ru/plugins/oauth/callback`. The secret is shown once (only a hash is stored):

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

Ask the user for read-only vs read+write if unclear; default to read+write only with their approval.

2. **Hand over the credentials in the same reply**, as one copyable block. Never split client_id and secret across messages; never write them to the repo, `cfg/`, logs or tickets:

```text
MCP URL:            https://YOUR_HOST/llm/mcp
Authorization URL:  https://YOUR_HOST/oauth/authorize
Token URL:          https://YOUR_HOST/oauth/token
client_id:          fs_…
client_secret:      …
Credentials:        in header
Scopes:             ops.read ops.write
```

Tell the user to pick the **"in header"** option where the form asks how to send client credentials (HTTP Basic; `/oauth/token` also accepts body fields).

3. **Warn about the empty field.** The form leaves one field empty (`client_secret`); the user must fill it in by hand, otherwise token exchange fails with `invalid_client`. If the secret is lost: **LLM → OAuth / MCP** (`/llm/oauth`) → **Rotate client secret**, hand over the new one. Do not create duplicate clients.

4. **Verify by reading.** After the user completes login → Allow, have Alice call `core__modules__list` (read-only; needs `core.modules`). A module list means the whole chain works. Do not verify with write tools.

5. **Empty tool list — check in order:**
   - `assistant` enabled (`php bin/freesaas.php module list --status=enabled --format=json`).
   - The consenting staff user has the permissions behind the ops (non-directors see only what their role allows).
   - Client scopes: write tools are hidden without `ops.write`; reconnect after changing scopes.
   - Authorization header reaches PHP (nginx: `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`) — otherwise consent succeeds but `tools/call` returns `401`.
   - Client in `/llm/oauth` is enabled and has the exact redirect URI above.
   - Alice caches the tool list: disconnect and reconnect the connector after fixes.

## Success criteria

- Client created once; credentials delivered in one copyable block with the "in header" note and the empty-field warning.
- `core__modules__list` returned data through Alice.
- No secrets stored anywhere except the user's Alice form.

## Safety

- Platform lifecycle (enable/migrate/bootstrap) stays on CLI or `/agent/*` + `X-FreeSaaS-Agent-Token`, not MCP.
- Do not grant `SUPERADMIN_ACCESS` to product staff to "fix" an empty tool list; fix role permissions instead.
- Do not commit `cfg/` or paste `client_secret` into docs.

## Canonical docs

| Topic | Path |
|-------|------|
| Connect MCP (this flow: "Yandex Alice connector") | `docs/agents/connect-mcp.md` |
| OAuth/MCP deep dive | `docs/agents/llm.md` |
| Auth surfaces | `docs/agents/agent-surfaces.md` |
