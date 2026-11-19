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

## Pull details

- **Volume:** `max_records` caps rows per run and entity (default `10000`, `0` = no limit). Paging is `order ID ASC`; a capped run reports `capped: true` and does not advance the incremental cursor.
- **Incremental cron:** `bitrix.import_contacts` (hourly) pulls company → contact → lead → deal with `>DATE_MODIFY` since the last complete real run (`meta_json.synced_at` in `base_bitrix_sync_state`, 10 min overlap). Before the first complete run it does a full pull.
- **Pipelines (`map_pipelines = 1`, default):** each Bitrix deal category becomes a local `kind=sale` pipeline, leads a `kind=lead` pipeline; stages come from `crm.status.list` (`DEAL_STAGE[_n]` / `STATUS`), code = `STATUS_ID` without `C<n>:`, semantic from `SEMANTICS`. Map rows: `base_bitrix_id_map` entity `pipeline`, ext_id `deal:<n>` | `lead`. Push rebuilds `C<n>:CODE` (+ `CATEGORY_ID` on add). `0` = legacy: first enabled pipeline of the kind, stage by code.
- **Contacts:** phone is the identity; `contacts_without_phone = 1` also imports e-mail-only contacts.
- Not imported: responsible user, UF_* custom fields, secondary deal contacts, activities.
