# Client MCP (public client server, per storefront domain)

Alpha product module `client_mcp`. Anonymous MCP server so any MCP-capable agent (Claude, ChatGPT, Cursor, …) can act on behalf of a client without plugins: business info → services → free slots → client identity → booking → own bookings / cancel → payment info.

**One server per storefront host.** `https://likespa.ru/mcp` and `https://balance-spa.ru/mcp` are different servers: own on/off switch, name, consent link, tool set, services (host catalog roots via `SitesCatalog`) and sessions (a `client_token` from one domain is rejected on another). Admin and unknown hosts answer **404** on `/mcp`, so the staff MCP OAuth metadata (`/.well-known/oauth-*` → `/llm/mcp`) stays unambiguous.

Not the staff MCP: `/llm/mcp` is OAuth + staff permissions ([connect-mcp.md](connect-mcp.md), [agent-surfaces.md](agent-surfaces.md)).

## Enable

```bash
php bin/freesaas.php module enable client_mcp --accept-risk --format=json
php bin/freesaas.php migrate run --module=client_mcp --format=json
```

Hard deps: `sites`, `clients`. Booking tools appear when `booking`, `catalog` (+ `orders` for book/list/cancel) are on and the storefront booking engine is active.

Then a director opens **Sites → Client MCP** (`/client-mcp/settings`) and enables MCP per domain. Until then `/mcp` answers 503 on that domain; server card / catalog entry are absent.

Diagnose: `php bin/freesaas.php health --format=json` → `client_mcp.endpoint` (skipped when no domain enabled; fail when a domain has no product tools); storefront booking: `GET /sites/status?format=json`.

## Endpoints (each storefront host)

| Path | Owner | What |
|------|-------|------|
| `POST /mcp` | client_mcp | MCP Streamable HTTP (JSON responses, stateless; protocols `2025-11-25`, `2025-06-18`, `2025-03-26`) |
| `GET /mcp` | client_mcp | Plain JSON info; `Accept: text/event-stream` → 405 (no server push) |
| `GET /mcp/server-card` | client_mcp | MCP Server Card of this host (`application/mcp-server-card+json`, ETag) |
| `GET /.well-known/ai-catalog.json` | **sites** | Domain AI Catalog; entries via EventBus `sites.ai_catalog` (404 when empty) |
| `/client-mcp/settings` | client_mcp | Director settings (`SUPERADMIN_ACCESS`); JSON with `Accept: application/json` |

Client config: `{"mcpServers":{"likespa-ru":{"url":"https://likespa.ru/mcp"}}}`. ChatGPT / Claude web: custom connector, URL `https://<domain>/mcp`, authentication **none**.

**Agents do not auto-connect.** ChatGPT / Claude browse a site as HTML; MCP tools appear only after the user adds the connector. To make browsing agents notice and suggest the server, while MCP is on for the domain the module adds (all removed when the domain MCP or the module is off):

| Hint | Where |
|------|-------|
| `<link rel="alternate" type="application/mcp-server-card+json" href="…/mcp/server-card">` + `<meta name="mcp-server" content="…/mcp">` | `<head>` of every storefront page (via `sites.page.inject`) |
| Visible line «book via AI assistant: connect {url}» + link to `/llms.txt` | before `</body>`; per-domain toggle + custom text (`{url}` placeholder) in settings |
| Section «AI assistants (MCP)» with URL, card, tools, ChatGPT/Claude/Cursor connect steps, instructions for the assistant | `/llms.txt` (via `sites.llms_txt`) |
| Entry `application/mcp-server-card+json` | `/.well-known/ai-catalog.json` (via `sites.ai_catalog`) |

## Built-in tools

| Tool | Provider | Requires | Notes |
|------|----------|----------|-------|
| `business_info` | core | — | Name, phone, website, timezone, currency, payment policy, `consent_url`, registration package; providers extend via `client_mcp.business_info` |
| `identify_client` | core | — | `phone` (+ `client_name`, `consent`) → `client_token`, `required_fields`, `verification.mode` |
| `verify_code` | core | — | Only when `verification.mode` ≠ `none` |
| `list_services` | booking | booking, catalog | Services visible on this host, storefront price (`SitesCatalog::onlinePrice`) |
| `list_specialists` | booking | booking, catalog | First name + photo + first free date |
| `get_availability` | booking | booking, catalog | No `date` → dates; with `date` → `HH:MM` slots per specialist + `any_specialist_slots` |
| `create_booking` | booking | booking, catalog, orders | `date` + `time` (or `dt_start`), `operator_id` (0 = any) → `BookingBook` `source=mcp` |
| `my_bookings` | booking | orders | Orders of this session (all future ones when verified) |
| `cancel_booking` | booking | orders | Session-owned / verified order → `OrdersRepository::cancelForClient` |

Core tools cannot be switched off; others can be unticked per domain.

Tool errors: `isError: true` + `structuredContent` `{ok:false, error, message, …}`; codes: `invalid_phone`, `blocked_online`, `rate_limited`, `invalid_token`, `missing_fields` (+ `required_fields`), `invalid_services`, `invalid_date`, `date_out_of_range`, `invalid_time`, `slot_unavailable`, `booking_failed`, `not_found`, `not_cancellable`, `not_available`.

## Adding tools from another module

Subscribe in your module `bootstrap.php` (soft dep `client_mcp` in `optionalRequires`; works regardless of boot order):

```php
EventBus::on('client_mcp.tools', static function ($payload) {
    $payload['tools'][] = [
        'name' => 'bonus_balance',                 // public contract, a-z0-9_
        'title' => 'Loyalty balance',
        'description' => 'Bonus balance of the identified client.',
        'inputSchema' => ['type' => 'object', 'properties' => ['client_token' => ['type' => 'string']], 'required' => ['client_token']],
        'annotations' => ['readOnlyHint' => true],
        'handler' => static function (array $args): array {
            $session = ClientMcpContext::session($args);            // throws invalid_token
            if (!ClientMcpContext::isVerified($session)) {
                throw new ClientMcpToolError('verification_required', t('bonuses.mcp.err.verify'));
            }
            return ['ok' => true, 'balance' => BonusRepository::balance((int)$session['client_id'])];
        },
        'module' => 'bonuses',
        'requires' => ['bonuses'],
        'ready' => null,                            // optional callable(): bool
        'order' => 80,
        'instructions' => '',                       // optional hint for initialize.instructions
    ];
    return $payload;
});
```

`ClientMcpContext` (public API): `host()`, `session($args)`, `optionalSession($args)`, `isVerified($session)`, `rememberOrder()`, `ownsOrder($session, $order)`, `payment($order, $amount, $paid)`, `paymentPolicy()`, `businessPhone()`, `absoluteUrl()`, `cleanName()`, `clientIp()`. The new tool shows up in `/client-mcp/settings` with a per-domain checkbox.

## Domain

- Booking = `BookingBook::createOrder` with `source='mcp'` (registered via EventBus `orders.sources`), storefront lead `sites.booking_lead_sec`, lines at storefront price → `base_orders` (pay on site). Comment: «Booked via AI agent (<host>)». Emits `booking.book.*`, `orders.created`, `client_mcp.booking.created` (`order_id`, `site_host_id`, `host`, `session_id`, `client_id`, `lines`, `operator_id`, `dt_start`).
- Order state for clients comes from `OrdersRepository::clientState()` (`state`, `paid`, `cancellable`) — no STATUS parsing in channels.
- Sessions: `base_client_mcp_sessions` (token sha256, `SITE_HOST_ID`, TTL `token_ttl_hours`, session order ids). Per-host settings: `base_client_mcp_hosts`. Daily cron `client_mcp.purge`.
- Limits (all hosts, ini): `sessions_per_ip_hour`, `bookings_per_phone_day`; `require_name_for_new`, `currency`.
- Public data only: specialist first name + photo (`SitesBooking::operatorPublicCard`); client names are never returned.
- Known limits: `base_orders` has no host column (host is in the comment and in `client_mcp.booking.created`); specialists/shifts are instance-wide (booking engine), only services are split by host; phone normalization is the storefront one (`SitesGuest::normalizePhone`, `7XXXXXXXXXX`).

## Trust model and hooks

Default `verification = none`: the phone the agent passes is trusted, same as the website `/cart`.

| Hook | Payload | Use |
|------|---------|-----|
| `client_mcp.tools` | `tools[]` | Contribute tools (see above) |
| `client_mcp.business_info` | `info`, `host` | Add sections / registration fields |
| `client_mcp.identify` | `phone`, `client_id`, `known`, `client_name`, `ip`, `site_host_id`, `verification{mode,hint}`, `error` | Set `verification.mode='code'` + `hint` and send an OTP; or `error` to refuse |
| `client_mcp.verify` | `session`, `code`, `verified`, `client_id`, `error` | Check the code; set `verified=true` (optionally `client_id`) |
| `client_mcp.payment` | `order`, `payment{required,mode,status,amount,currency,url,qr,expires_at,instructions}`, `site_host_id` | Default = pay-on-site. A future acquiring module (own tables, not `base_orders` columns) fills `mode='online'`, `url`/`qr`, `expires_at` |
| `client_mcp.booking.created` | see Domain | Notifications, CRM, analytics |

## Related

- [booking.md](booking.md) — slot engine, storefront wizard
- [sites.md](sites.md) — hosts, catalog roots, `sites.ai_catalog`, `sites.status`
- [orders.md](orders.md) — `SOURCE=mcp`, `orders.sources`, cancel
- [mcp-catalog.md](mcp-catalog.md) — listing in catalogs
