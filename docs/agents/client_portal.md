# Client portal

Storefront personal cabinet for `base_clients`: login at `/cabinet/login`, shell at `/cabinet`, sections via EventBus.

## Enable

```bash
php bin/freesaas.php module enable client_portal --accept-risk --format=json
php bin/freesaas.php migrate run --module=client_portal --format=json
```

Soft sections appear when those modules are enabled **and** visible under `/client-portal/settings`.

## Human UI

| Path | Role |
|------|------|
| `/cabinet/login` | Phone + password (`base_clients`) |
| `/cabinet` | Home cards |
| `/cabinet/{section}` | Section body from EventBus `client_portal.render` |
| `/client-portal/settings` | Section visibility / order (superadmin) |
| `/client-portal/status` | Diagnose |

Storefront chrome: theme views `cabinet` / `cabinet_login` (shipped in `themes/default`, private `likespa` / `balance`; fallback `modules/sites/views/`). Nav link via EventBus `sites.nav` (Cabinet / Sign in).

## EventBus contract

Owning modules register on `client_portal.sections` (`id`, `module`, `title`, `weight`, `nav`, `home_card`), optionally fill `client_portal.home` cards and `client_portal.render` HTML.

Visibility: `module_enabled && setting_show[id] !== false`.

## Agent ops

See `module.json` → `agent.ops`. Storefront `/cabinet*` ops have `assistant: false`.
