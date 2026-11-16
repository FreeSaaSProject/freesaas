# Online acquiring

Multi-provider online payments. Own tables `base_acquiring_*` — not columns on `base_orders`. Staff `payments` module is unrelated.

## Enable

```bash
php bin/freesaas.php module enable acquiring --accept-risk --format=json
php bin/freesaas.php migrate run --module=acquiring --format=json
# Copy modules/acquiring/config.example.ini → cfg/modules/acquiring.ini
```

## Providers

Registry `AcquiringRegistry`: `yookassa`, `tbank`. Several may be enabled; `default_provider` + optional client choice. Empty credentials + enabled = stub pay URL for local demos (`/acquiring/checkout?stub=1…` marks succeeded).

Webhook: `POST /acquiring/webhook/{provider}` (CSRF-exempt).

On success emits `acquiring.payment.succeeded` (`order_id`, `client_id`, `amount`, `provider`, `provider_ref`). Fills `client_mcp.payment` and `orders.client_state` paid flag.

## Human UI

| Path | Role |
|------|------|
| `/acquiring/payments` | Payment list |
| `/acquiring/settings` | Providers (superadmin) |
| `/acquiring/status` | Diagnose |
| `/acquiring/checkout` | Create payment / stub confirm |

## Agent ops

See `module.json` → `agent.ops`.
