# AmoCRM / Kommo bridge (`amocrm`)

Bidirectional sync with FreeSaaS [`clients`](clients.md) + [`crm`](crm.md). Shared entity contract: [`crm.md`](crm.md).

## Mapping

| Amo / Kommo | FreeSaaS |
|-------------|----------|
| Contact | `base_clients` |
| Company | `base_crm_companies` |
| Lead | `base_crm_deals` in a **sale** pipeline |

There is no separate Amo “deal” — leads are the pipeline objects.

## Dual-run

Same pattern as [`bitrix`](bitrix.md): id map + sync state + outbox + webhook claim; `CrmSyncContext` / `sync_origin=amocrm` suppresses echo.

Webhook idempotency key is `entity:op:id:revision` (`updated_at` / `last_modified`, else a payload fingerprint) — not `id` alone, so successive updates of the same lead are not dropped as duplicates. A failed apply releases the claim so Amo can retry.

## Config

```bash
cp modules/amocrm/config.example.ini cfg/modules/amocrm.ini
# base_url + access_token + webhook_token + worker_token
php bin/freesaas.php module activate amocrm --action=enable --accept-risk --format=json
php bin/freesaas.php migrate run --module=amocrm --format=json
```

Jobs: `X-Amocrm-Token`. Webhook: `X-Amocrm-Webhook-Token` or `?token=`.
