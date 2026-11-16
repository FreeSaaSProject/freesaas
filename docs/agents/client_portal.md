# Client portal

Storefront personal cabinet for `base_clients`: login at `/cabinet/login`, shell at `/cabinet`, sections via EventBus.

## Enable

```bash
php bin/freesaas.php module enable client_portal --accept-risk --format=json
php bin/freesaas.php migrate run --module=client_portal --format=json
```

Soft sections appear when those modules are enabled **and** visible under `/client-portal/settings`.

**Bots on storefront:** password reset needs client bot deep links / enqueue on the public host. `bots` ships with `runtime: both` (≥1.0.6). Enabling/migrating `client_portal` also runs migration `003_ensure_bots_storefront_runtime` which patches on-disk `modules/bots/module.json` to `runtime: both` if an older copy is still `admin`-only. Set `client_telegram_username` / `client_max_bot_url` under `/bots/settings`.

## Human UI

| Path | Role |
|------|------|
| `/cabinet/login` | Phone + password (`base_clients`), RU phone mask |
| `/cabinet/forgot` | Open client Telegram/Max bots — password is generated in the bot (no form) |
| `/cabinet/reset?token=` | Legacy token form (agent/debug); preferred path is in-bot generate |
| `/cabinet` | Home cards |
| `/cabinet/{section}` | Section body from EventBus `client_portal.render` |
| `/client-portal/settings` | Section visibility / order (superadmin) |
| `/client-portal/status` | Diagnose |

Storefront chrome: theme views `cabinet` / `cabinet_login` / `cabinet_forgot` / `cabinet_reset` (shipped in `themes/default`, private `likespa` / `balance`; fallback `modules/sites/views/`).

**Nav:** one dynamic link via `sites.nav` + `sites.footer_pages` — «Sign in» → «Cabinet». CMS duplicates (`/lk/`, «Кабинет», «Вход») are stripped.

## Password reset

1. Site `/cabinet/forgot` only shows **Open Telegram / Open Max** (deep links from `client_telegram_username` / `client_max_bot_url`).
2. In the **client** bot cabinet: **Password restore** (`ClientPasswordRestoreBotPlugin`) generates a new password immediately and shows it in chat (same idea as staff `password_restore`).
3. Client must be linked (share phone / link-code). Unlinked users authorize in the bot first.
4. Agent `POST /agent/client-portal/password-reset` still exists for staff debugging (returns `reset_url`).

## EventBus contract

Owning modules register on `client_portal.sections` (`id`, `module`, `title`, `weight`, `nav`, `home_card`), optionally fill `client_portal.home` cards and `client_portal.render` HTML.

Visibility: `module_enabled && setting_show[id] !== false`.

## Agent ops

See `module.json` → `agent.ops`. Storefront `/cabinet*` ops have `assistant: false`.
