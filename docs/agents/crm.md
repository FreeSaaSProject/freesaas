# Sales CRM (`crm`) + Bitrix / Amo bridges

Slim **sales CRM** for FreeSaaS: companies, deals (incl. leads via pipeline kind), pipelines/stages, contact links, custom fields, notes. Contacts remain in [`clients`](clients.md) (`base_clients`). Suppliers / orders / calendar stay separate SoTs.

Bridges: [`bitrix`](bitrix.md), [`amocrm`](amocrm.md). Dual-run (bidirectional sync) is the base scenario; cutover is optional later.

## Canonical entities

| FreeSaaS | Table(s) | Bitrix REST | Amo / Kommo v4 |
|----------|----------|-------------|----------------|
| Contact | `base_clients` | `crm.contact.*` | `/api/v4/contacts` |
| Company | `base_crm_companies` + `base_crm_company_contacts` | `crm.company.*` (+ requisites → CF/INN cols) | `/api/v4/companies` |
| Deal (sale) | `base_crm_deals` (`pipeline.kind=sale`) | `crm.deal.*` | `/api/v4/leads` |
| Lead | same `base_crm_deals` (`pipeline.kind=lead`) | `crm.lead.*` | (no separate entity — map to lead pipeline) |
| Pipeline | `base_crm_pipelines` | `crm.category.*` / deal categories | `/api/v4/leads/pipelines` |
| Stage | `base_crm_stages` (`semantic` P\|S\|F) | `crm.status.*` (`STAGE_ID`) | pipeline `_embedded.statuses` |
| Custom field def | `base_crm_custom_fields` | `crm.*.userfield.*` / UF_* | `/{entity}/custom_fields` |
| Custom value | `base_crm_custom_values` | UF_* on entity get/update | `custom_fields_values` |
| Note | `base_crm_notes` | `crm.timeline.comment.*` (subset) | `/api/v4/{entity}/notes` |
| Stage history | `base_crm_stage_history` | stage change events | status change webhooks |
| Ext id map | `base_bitrix_id_map` / `base_amocrm_id_map` | entity id | entity id |
| Sync state | `base_*_sync_state` | cursors / last_run | same |
| Outbox | `base_*_outbox` | push queue | push queue |
| Webhook claim | `base_*_webhook_events` | outbound webhook / offline | account webhooks |

**Lead rule:** one local row type — `deal` — distinguished by **pipeline.kind** (`lead` \| `sale`). Bitrix leads land in a lead-kind pipeline; Bitrix deals and Amo leads land in sale-kind pipelines (or mapped pipelines).

**Not** SoT for companies: [`suppliers`](suppliers.md) / `base_suppliers` (procurement + ledger counterparty). Optional later link by INN only.

**Not** SoT for deals: [`orders`](orders.md) / `base_orders` (checkout/booking lifecycle strings). Soft link fields may appear later; do not merge pipelines into `STATUS`.

## Domain events

| Event | When | Payload keys |
|-------|------|--------------|
| `clients.created` / `clients.updated` | `ClientsRepository` create/update | `id`, `data`, optional `sync_origin` |
| `crm.company.created` / `.updated` / `.deleted` | company CRUD | `id`, `row`, `sync_origin` |
| `crm.deal.created` / `.updated` / `.deleted` / `.stage_changed` | deal CRUD / stage move | `id`, `row`, `from_stage_id`, `to_stage_id`, `sync_origin` |
| `crm.note.created` | note add | `id`, `entity`, `entity_id`, `sync_origin` |
| `crm.deal.timeline` | deal card / `crm.deals.get` build the feed | `deal_id`, `deal`, `items[]` — append `{type, id, at, author_id, text \| html, kind_label}` (html via `safe_html`) |

Contact add/remove/role/primary on a company or deal also emit `crm.company.updated` / `crm.deal.updated`.

## Human UI

- `/crm/deals` — list (company, stage pill, amount, responsible) or **kanban** `?view=kanban&pipeline_id=` (columns = enabled stages, count + sum per currency, drag-and-drop → same `move_stage` POST). Filters: search, pipeline, responsible (`responsible=me` = «Мои»).
- `/crm/deals/quick` — **new lead** in one form: company (existing / matched by INN → exact name / created) + contact person (matched by phone / created with `clients.edit`, linked to the company with a role) + deal. One DB transaction.
- `/crm/deals/{id}` — card: stage bar on top (click = move stage), left column details (amount, pipeline, responsible, source, typed custom fields, description) + contacts (role from company, primary, add from company / by phone, unlink), right column note form + timeline (notes + stage changes with author, newest first; other modules extend via `crm.deal.timeline`).
- `/crm/deals/{id}/edit`, `/crm/companies/{id}/edit` — responsible is a staff picker (`UsersRepository::staffNamesIncluding`), custom fields render by type (`bool` checkbox, `enum` select from `enums_json`, `text` textarea, `number`). Contacts are managed on the card, not in the form.
- `/crm/companies/{id}` — card with contacts + role (`base_crm_company_contacts.role`, e.g. «ЛПР», «контактное лицо»), deals of the company, notes.
- Seeded pipelines: `Leads` (lead), `Sales` (sale), `B2B` (sale: new lead → meeting → questionnaire sent → questionnaire received → proposal → pilot/contract → won/lost). Stage names are data, not i18n.

Bridges set `CrmSyncContext` / `sync_origin` (`bitrix` \| `amocrm`) while applying inbound so outbox listeners **skip echo**.

## Out of scope (v1)

Documented gaps — **do not** build inside `crm` / bridge modules:

| External | Policy |
|----------|--------|
| Bitrix projects / workgroups (`sonet_group.*`) | Stay in Bitrix; no FreeSaaS workgroup module |
| Bitrix Smart Processes | Out of scope |
| Bitrix news feed (`log.blogpost.*`) | Optional one-way archive → `base_news` TYPE=1 only; no reverse |
| Bitrix / Amo tasks | Soft one-way → `base_news` TYPE=2 optional; no UF_CRM_TASK parity |
| Full telephony / activity stream | Notes + stage history only |
| Amo Digital Pipeline robots / talks | Out of scope |
| Deal product rows ↔ catalog | Phase 2+ |
| Company ↔ `base_suppliers` by INN | Phase 4 helper only |

## Maturity

All three modules ship as **`alpha`** until a human has exercised dual-run against a real Bitrix/Amo portal (or local CRM CRUD has been soak-tested). Enable needs `accept_risk` / `--accept-risk`.

## Enable (agent / CLI)

```bash
php bin/freesaas.php module activate crm --action=enable --accept-risk --format=json
# grant director crm.* permissions via activate snapshot/apply
php bin/freesaas.php migrate run --module=crm --format=json
# optional bridges:
php bin/freesaas.php module activate bitrix --action=enable --accept-risk --format=json
php bin/freesaas.php module activate amocrm --action=enable --accept-risk --format=json
```

Copy `modules/bitrix/config.example.ini` → `cfg/modules/bitrix.ini` (and amocrm) before pull/push. Secrets are director-only (`SUPERADMIN_ACCESS` on `/bitrix/settings`, `/amocrm/settings`).

## Agent ops (assistant / MCP session)

Same routes as UI; `?format=json` or `Accept: application/json`. Catalog: `module.json` → `agent.ops`.

| Area | Typical sequence |
|------|------------------|
| New B2B lead | `crm.deals.quick_form` → `crm.deals.quick_create` (company + contact with role + deal; dedupe by INN/name and phone) |
| CRM day-to-day | `crm.deals.list` (`view=kanban`, `responsible=me`) → `crm.deals.get` (timeline) → `crm.deals.move_stage` / `crm.deals.add_note` / `crm.deals.update` (PATCH) |
| Contacts | `crm.companies.add_contact` / `set_contact_role` / `set_primary_contact` / `remove_contact`; `crm.deals.add_contact` (`link_company=1`) / `remove_contact` / `set_primary_contact` |
| Classic forms | `crm.deals.new_form` → `crm.deals.create`; companies + `crm.pipelines.list` |
| Dictionaries | `crm.pipelines.*`, `crm.stages.*`, `crm.fields.*` |
| Bitrix dual-run | `bitrix.settings.save` (rest_url + tokens) → `bitrix.ping` → `bitrix.import` with `dry_run=1` then `0` |
| Amo dual-run | `amocrm.settings.save` → `amocrm.ping` → `amocrm.import` dry_run then write |

**Agent cannot invent** Bitrix/Amo secrets — ask the user, then `*.settings.save`. Worker/cron job URLs stay out of the assistant catalog (`jobs` + token header); use after settings are saved.

Contacts stay on [`clients`](clients.md) ops (`clients.create` / `clients.update`); CRM links them via the contact ops above (or `client_ids` on company/deal writes, which replaces the whole list).

**PATCH rule:** `crm.deals.update` / `crm.companies.update` keep every field the request did not send (incl. contacts when `client_ids` is absent, custom fields not in `cf[]`). Send only what changed.
