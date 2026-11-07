# Bitrix24 CRM bridge (`bitrix`)

Bidirectional sync between Bitrix24 CRM and FreeSaaS [`clients`](clients.md) + [`crm`](crm.md). Entity contract: [`crm.md`](crm.md).

## Scope

| Bitrix | FreeSaaS |
|--------|----------|
| Contact | `base_clients` |
| Company | `base_crm_companies` |
| Deal | `base_crm_deals` (pipeline `kind=sale`) |
| Lead | `base_crm_deals` (pipeline `kind=lead`) |

Out of scope: projects/workgroups, Smart Processes, full activity stream (see crm.md).

## Dual-run plumbing

- **Pull:** `BitrixImporter` + cron / `/bitrix/jobs/import` + UI dry-run
- **Inbound:** outbound webhook from Bitrix → `POST /bitrix/webhook` (`webhook_token`)
- **Push:** EventBus → `base_bitrix_outbox` → `/bitrix/jobs/outbox` / cron (`CrmSyncContext` / `sync_origin=bitrix` skips echo)

## Config

```bash
cp modules/bitrix/config.example.ini cfg/modules/bitrix.ini
# set rest_url (incoming webhook), portal, webhook_token, worker_token
php bin/freesaas.php module activate bitrix --action=enable --accept-risk --format=json
php bin/freesaas.php migrate run --module=bitrix --format=json
```

Jobs auth: header `X-Bitrix-Token` = `worker_token`. Webhook: `X-Bitrix-Webhook-Token` or Bitrix `application_token` matching `webhook_token`.
