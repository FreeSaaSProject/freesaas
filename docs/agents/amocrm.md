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

Webhook idempotency key is `entity:op:id:revision:fingerprint` — revision from `updated_at` / `last_modified` (or `0`) plus a short payload hash, so two updates with the same timestamp but different fields are not dropped as duplicates. A failed apply releases the claim so Amo can retry.

Sparse webhook payloads (status/responsible change with only id + one field) **merge** into the existing local deal/company/contact: absent `name`/`price`/`status_id`/`_embedded` keep current values. `responsible_user_id` is mapped via id-map `entity=staff` (Amo user → `base_users`); without a staff map the local responsible is left unchanged — never write the raw Amo user id.

## Config

```bash
cp modules/amocrm/config.example.ini cfg/modules/amocrm.ini
# base_url + access_token + webhook_token + worker_token
php bin/freesaas.php module activate amocrm --action=enable --accept-risk --format=json
php bin/freesaas.php migrate run --module=amocrm --format=json
```

Jobs: `X-Amocrm-Token`. Webhook: `X-Amocrm-Webhook-Token` or `?token=`.
