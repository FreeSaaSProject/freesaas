# Docs (documents + medical cards)

Product module `docs`: one engine for printable documents (consents, checks) **and** medical cards. Catalog maturity: `alpha`.

URL prefix is `/documents` (not `/docs`: `.htaccess` forbids `^docs/` because the repo's `docs/` folder must not be served). Module id, permissions (`docs.*`) and tables (`base_doc_*`) keep the short name.

Medcard is not a separate module: it is a set of document **kinds** over the same definitions / instances / print pipeline. Legacy export stacks had two paths (`base_docs*` + `printdoc.php` for consents; hardcoded `getCardData()` 025/u over client profile + `base_procedures` columns for medcards). FreeSaaS keeps clinical data **only** in `base_doc_*` — never as columns on `base_clients` / `base_procedures`.

## Enable

```bash
php bin/freesaas.php module enable docs --accept-risk --format=json
php bin/freesaas.php migrate run --module=docs --format=json
cp modules/docs/config.example.ini cfg/modules/docs.ini   # optional: qr_archive
```

Default definitions seeded on first open of `/documents` (or any `DocsDefsRepository::ensureSeeded()` call — current locale):

| Code | Kind | Scope | Trigger | Enabled |
|------|------|-------|---------|---------|
| `medcard_profile` | medcard_profile | client | manual | yes |
| `medcard_visit` | medcard_visit | event | medical | yes |
| `medcard_dossier` | medcard_dossier | client | manual | yes |
| `consent_medical` | consent | client | medical | no (legacy sample) |
| `contract_citizen` | contract | client | medical | yes — print body from `library/elos63/d_zg` (rich Д-ЗГ) |
| `consent_list` | consent | client | medical | yes — print body from `library/elos63/ids_p` (Перечень 390н) |
| `consent_procedure` | consent | event | medical | yes — short ИДС for the visit basket |

Untouched seeds upgrade when `DocsSeed::REVISION` rises (`cfg` key `docs.seed_revision`); unprinted instances of those defs are retargeted to the new version. Edited definitions (more than one version) are left alone. New codes appear on the next `ensureSeeded()` even if revision already matches.

Permissions: `docs.view`, `docs.edit`, `docs.print`, `docs.medical` (medcard kinds), `docs.manage` (definitions/templates), `docs.sign` (QR archive sign). Calendar section needs role flag `EVT_DOC_LINKS` (or superadmin).

## Agent checklist: make medcards work

Use when the business needs ambulatory cards (form 025/у) or visit sheets. Skip this module entirely for pure salon/retail stands that never print clinical charts.

1. **Dependencies on:** `core`, `clients`, `calendar` (+ `workweeks`), `procedures` (medical types). Soft: `audio` / `audio_llm` for autofill proposals.
2. **Enable + migrate** as above (`--accept-risk`: maturity `alpha`). Confirm `php bin/freesaas.php module inspect docs --format=json` → enabled, no migration errors.
3. **Org requisites** — print header `{org.*}`: UI `/documents/settings` or agent `docs.settings` with `org[name]`, `org[address]`, `org[inn]`, `org[ogrn]`, licence fields, optional `org[signature]` URL. Empty org → blank underlines on the title page.
4. **Roles:** grant staff `docs.view` / `docs.edit` / `docs.print` / `docs.medical`; directors also `docs.manage`. On doctor/admin statuses set `EVT_DOC_LINKS` so calendar event edit shows the Documents block.
5. **Medical procedure types:** at least one `base_procedure_types.IS_MEDICAL=1` and products linked to it. Seeded `medcard_visit` trigger is `medical` — shells spawn only when the visit basket has a medical product.
6. **Open once** `/documents` (or call any docs page) so seed defs exist. Codes that matter:
   - `medcard_profile` — standing part (OMS, work, anamnesis life/allergy, skin/mucosa/nails, blood, …)
   - `medcard_visit` — per-visit sheet (`complaints`, `ana_gen`, `ana_obj`, `dia_codes`, `doctor_id`, `recommends`, …)
   - `medcard_dossier` — full card print (aggregates profile + visits)
   - `contract_citizen` — rich paid-services contract (library `elos63/d_zg`; one per client; medical trigger)
   - `consent_list` — rich «Перечень» IDS (library `elos63/ids_p`; one per client)
   - `consent_procedure` — short ИДС listing `{event.items}` (one per medical event)
   - Clinic demo also turns on `docs.qr_archive=1` (archive QR on prints; needs stickers’ phpqrcode)
7. **Smoke path:** create/open a calendar event with a medical service → Documents section shows visit sheet + ИДС (+ contract once per client) → fill visit until `ready` → print ИДС/contract → client → «Медкарта» / `/documents/clients/{id}/medcard`. Item 20 / stage epicrisis read **only** def code `medcard_visit`.
8. **What fills what on 025/у:**
   - Title (1–23): client + `medcard_profile` (`{profile.*}`; use `{profile.work_position|__________}` style fallbacks)
   - Item 20 `{dossier.diagnoses}`: unique `dia_codes` (+ doctor) across official visit sheets
   - Visit pages 24/25: visit payload; on the **first** visit also `{visit.anamnesis_life}` / `{visit.objective_general}` from profile (`ana_life`, `ana_gyno`, `ana_alerg`, `obj_skin`, `obj_sliz`, `obj_nogot`, optional height/weight/BMI)
   - Item 26 `{dossier.stage_epicrisis}`: auto blocks from visits that have clinical text (complaints / `ana_*` / `dia_codes` / `recommends`); blank paper form if none
9. **Do not** store clinical text on `base_clients` / `base_procedures` columns — only in `base_doc_instances.PAYLOAD`.
10. **Demo clinic pack** (`demo` profile `clinic`): stage `docs` fills standard profile + visit fields for a subset of events; see [`demo.md`](demo.md). Production stands use staff entry or LLM proposals, not the demo pack.

## Layers

| Layer | Table | Notes |
|-------|-------|-------|
| Definition | `base_doc_defs` | `CODE` (unique), `KIND`, `SCOPE` (`client`\|`event`), trigger (`TRIGGER_MODE` + `TRIGGER_TYPES`/`TRIGGER_PROCS` JSON), `EXPIRE_DAYS`, `REQUIRE_MEDCARD`, `ID_VERSION_CURRENT` |
| Version | `base_doc_def_versions` | Immutable: `SCHEMA_JSON`, `PRINT_HTML`, `ORIENTATION`, `FORMAT`, `COPIES`. Editing schema/template creates a new version; instances keep their `ID_VERSION` |
| Instance | `base_doc_instances` | `ID_CLIENT`, `ID_EVENT`, `ID_PROCEDURE`, `KIND` (denormalized), `STATUS`, `PAYLOAD` JSON, `PRINT_SNAPSHOT` (frozen HTML), `HASH`, `HASH_EXT`, `DT_PRINT`, `DT_EXPIRE`, sign fields |
| Search index | `base_doc_field_index` | Only fields with `"index": true` (diagnosis code, doctor id). Never a copy of the whole payload |
| Autofill proposals | `base_doc_proposals` | Pending values from audio/LLM/import; staff accepts or rejects |

Indexes: instances `(ID_CLIENT, KIND, STATUS)`, `(ID_EVENT)`, `(ID_PROCEDURE)`, unique `HASH`, `HASH_EXT`. Lists and calendar sections read id + meta columns; `PAYLOAD` / `PRINT_SNAPSHOT` are loaded only on the instance page / print.

## Kinds

| Kind | Scope | Purpose |
|------|-------|---------|
| `consent` | client (reused until `DT_EXPIRE`) or event | Informed consents / notices (legacy `base_docs`) |
| `contract` | client | Service contracts; `{contract.id}` / `{contract.date}` in acts point to the latest one |
| `check` | event | Commercial printouts (basket table, totals) |
| `medcard_profile` | client (one current) | Standing anamnesis / objective status (legacy client `ANA_*`, `OBJ_*`) |
| `medcard_visit` | event (+ optional procedure) | Visit sheet: complaints, exam, diagnosis, recommendations, doctor, talk segments (legacy `base_procedures.ANA_*`, `DIA_CODE`, `RECOMENDS`, `ID_DOCTOR`) |
| `medcard_dossier` | client | Print recipe: profile + all visit sheets (legacy `getCardData` / `base_card_logs`). Each print is an instance (audit) |

## Schema JSON (version)

```json
{
  "sections": [
    {
      "id": "exam",
      "title": "Exam",
      "fields": [
        {"id": "ana_obj", "type": "textarea", "label": "Objective status", "required": true},
        {"id": "dia_codes", "type": "text", "label": "Diagnosis (ICD-10)", "index": true},
        {"id": "doctor_id", "type": "staff", "label": "Doctor", "required": true, "index": true},
        {"id": "talk_segments", "type": "segments", "label": "Time with patient"}
      ]
    }
  ]
}
```

Field ids: `^[a-z][a-z0-9_]{0,63}$`, unique per schema, **stable across versions** (autofill and the search index key on them). Types: `text`, `textarea`, `number`, `date`, `checkbox`, `select` / `multiselect` (`options: [{value,label}]`), `staff` (`base_users.id`), `segments` (list of `{from,to,note}` in `HH:MM`). Labels are template content (not i18n chrome).

Status: `draft` (some `required` field empty) → `ready` → `printed` (snapshot frozen, payload immutable) → `void`. "New revision" voids a printed instance and clones its payload into a new draft.

## Payload JSON (instance)

```json
{
  "ana_obj": "…",
  "doctor_id": 12,
  "talk_segments": [{"from": "14:05", "to": "14:40", "note": ""}],
  "_prov": {
    "ana_obj": {"source": "manual", "by": 7, "at": 1760000000},
    "talk_segments": {"source": "audio_records", "by": 7, "at": 1760000100, "record_ids": [991, 992]}
  }
}
```

`_prov` is per-field provenance: `source` = `manual` \| `audio_llm` \| `audio_records` \| `import`; optional `confidence`, `study_id`, `result_id`, `record_id(s)`.

## Print template placeholders

Template HTML is sanitized (tables, inline `style`, no scripts/forms/iframes) before merge. Values are HTML-escaped.

| Placeholder | Value |
|-------------|-------|
| `{field_id}` | Payload field of this instance (formatted) |
| `{fields}` | All schema sections as a definition list |
| `{client.name}` `{client.tel}` `{client.birthday}` `{client.passport}` `{client.address}` | Client |
| `{event.id}` `{event.date}` `{event.time}` `{event.items}` `{event.total}` `{event.staff}` | Calendar visit (basket table for `{event.items}`) |
| `{profile.field_id}` | Field of the client's current `medcard_profile` |
| `{client.mail}` `{client.passport_serie}` `{client.passport_number}` `{client.passport_issued_at}` `{client.passport_issued_by}` `{client.address_fact}` `{client.address_passport}` | Client details (legacy `{pass*}`, `{addressFact}`) |
| `{event.total_text}` `{event.procedures}` `{event.service_types}` | Sum in words, journal procedures, procedure type names |
| `{doctor.name}` `{doctor.position}` | Visit sheet doctor (or event operator), "Фамилия И.О." + role name |
| `{visit.recommends}` | Recommendations from the visit medcard sheet |
| `{contract.id}` `{contract.date}` | Client's latest `contract` document (acts reference it) |
| `{org.*}` | Organization requisites from `/documents/settings` (`DocsConfig::ORG_KEYS`; `{org.signature}` renders the facsimile image) |
| `{g:female|male|unknown}` | Gender form by client (`gender` 1 = male); legacy `[ая|ий|]` is converted to this |
| `{doc.id}` `{doc.code}` `{doc.name}` `{doc.date}` `{doc.expire}` | Instance meta |
| `{staff.name}` | Current user at first print |
| `{key\|default}` | Any placeholder with a fallback (escaped) when the value is empty, e.g. `{profile.snils\|__________}` |
| `{?flag:yes\|no}` | Conditional text; flags `visit.first` (first `medcard_visit` of the client), `client.male`, `client.female` |
| `{client.surname}` `{client.pname}` `{client.patronymic}` `{client.gender}` `{client.birth_day}` `{client.birth_month}` `{client.birth_year}` `{client.locality}` | Name/birth parts for form 025/у |
| `{card.no}` `{card.day}` `{card.month}` `{card.year}` | Card number (client id) and opening date (first visit) |
| `{visit.date}` `{visit.anamnesis_life}` `{visit.objective_general}` `{visit.next_text}` | Visit sheet helpers; on the first visit anamnesis/objective lines come from the profile (blanks when empty) |
| `{profile.bmi}` `{profile.ana_gyno_print}` | BMI with band; gynecological anamnesis ("not applicable" for men) |
| `{org.ogrn_boxes}` | OGRN digits in boxed cells |
| `{dossier.diagnoses}` `{dossier.visits}` `{dossier.procedures}` `{dossier.stage_epicrisis}` | 025/у item 20 table; all visit sheets; medical procedures list; item 26 stage epicrisis from filled visits |
| `{dossier}` | (`medcard_dossier` only) legacy profile + visit sheets list |
| `{qr}` | Archive QR (empty when `qr_archive=0`) |

Seed templates follow form 025/у (приказ Минздрава № 834н) as printed by legacy `getCardData`: `medcard_profile` = title page (items 1–23), `medcard_visit` = item 24 (first, B01.008.003) / 25 (follow-up, B01.008.004), `medcard_dossier` = title + all visits + procedures sheet + items 26–35. Untouched seeds are upgraded to the current seed revision automatically (`docs.seed_revision`); edited definitions are left alone.

## Template library and samples

Templates (definitions, never instances) can live as files instead of DB rows:

- `modules/docs/library/<pack>/<code>.json` — format `freesaas.doc-template` v1 (`code`, `kind`, `name`, `scope`, trigger, `expire_days`, `orientation`, `paper`, `copies`, `schema`, `print_html`, `source`); `pack.json` describes the pack.
- `/documents/defs/import` — pick templates from a pack (or upload a file / array of files); options: keep service/type id triggers (same DB only — otherwise `procs`/`types` become `manual`), update existing (new version), enable. Imported definitions start disabled.
- `/documents/defs/{id}/export` — download one definition as a template file.
- `/documents/defs/{id}/sample[?version=<version id>]` and `/documents/defs/import/sample?pack=&file=` — print a sample with demo data (watermark, nothing stored). Organization requisites use the real settings where filled, demo values otherwise.

### Legacy export (elos packs)

```bash
php bin/docs-export-legacy.php --schema=elos63 --name="Cosmetology (elos)" [--pack=elos63] [--dry-run] [--report] [--force]
```

- Reads `<schema>.base_docs` + `base_docs_datas`; exports only `ENABLED=1` and `DT_DISABLED=0`; identical texts collapse to the newest copy.
- Company data is detected by patterns (legal form + quotes incl. Latin `OOO`, ИНН/КПП/ОГРН/accounts, licence number/date/issuer/address/scope, tax registrar, addresses with street types, phones, the most linked own domain, director full/short names, city/region in grammatical case) and replaced with `{org.*}`. No company literals are stored in code.
- Legacy variables map to engine placeholders (`DocsLegacy::PLACEHOLDERS`), `[f|m|x]` → `{g:f|m|x}`, `QRID` → `{qr}`; local signature images → `{org.signature}`, other local images are dropped.
- Kind: `Д-…` → `contract`, `А-…` → `check`, else `consent`. Expiry 9999 → client scope without expiry; 1 → per visit.
- Every file is checked for residual company traces (plain text and raw HTML); a file with traces is not written without `--force`.

EventBus `docs.merge.context` lets other modules add placeholders (`payload.vars[key] = string`, escaped).

## Print and QR archive

- Staff print: `/documents/{id}/print` (`docs.print`). First open freezes `PRINT_SNAPSHOT`, sets `DT_PRINT` / status `printed`.
- `[docs] qr_archive=0|1` in `cfg/modules/docs.ini` (UI `/documents/settings`). `0`: no QR, `/documents/qr/{hash}` returns 404. `1`: QR → `/documents/qr/{HASH_EXT}`; the public page shows only doc name / number / print date / sign status (no PII); logged-in staff with `docs.sign` can sign as archivist.
- `REQUIRE_MEDCARD` on a definition blocks printing event-bound docs while the event's `medcard_visit` is not `ready`/`printed` (legacy `$MC_Filled` gate).

## Spawn (triggers)

On `calendar.event.saved` (and lazily when the event section renders) `DocsSpawner::ensureForEvent()` creates draft shells for enabled definitions whose trigger matches the visit basket:

| `TRIGGER_MODE` | Match |
|----------------|-------|
| `manual` | never auto |
| `every` | any non-block visit with a client |
| `medical` | basket has a product whose procedure type `IS_MEDICAL=1` |
| `non_medical` | no medical product |
| `types` / `procs` | product type id / product id in the JSON list |

`client` scope reuses an unexpired instance of the same definition. `medcard_visit` is event scope; `ID_PROCEDURE` is filled when the visit already has exactly one medical journal row.

## Autofill (audio)

Producers never write the payload directly — they emit **proposals**:

```php
EventBus::emit('docs.instance.propose', [
    'instance_id' => 0,            // or resolve by event_id / procedure_id / client_id + kind
    'event_id' => 123,
    'kind' => 'medcard_visit',
    'fields' => ['ana_gen' => '…', 'dia_codes' => 'L70.0'],
    'source' => 'audio_llm',
    'confidence' => 0.8,
    'study_id' => 5, 'result_id' => 77, 'record_id' => 991,
]);
```

- `audio_llm` emits `audio_llm.study.result` after a successful result; `docs` parses `RESULT_TEXT` when it is JSON with a `docs` object (`{"docs": {"kind": "medcard_visit", "fields": {...}}}`) and proposes into the visit of the study procedure (`ID_PROCEDURE` → `ID_EVENT_PARENT`).
- "Time with patient": instance page action builds a `talk_segments` proposal from `base_records` of the visit operator inside the event window (record ↔ visit link is computed by operator + time; `base_records` is not modified).
- Accepting a proposal writes the field with `_prov.source` from the proposal; unknown field ids are rejected.

## Legacy → field ids

| Legacy column | Kind | Field id |
|---------------|------|----------|
| `ANA_LIFE` | `medcard_profile` | `ana_life` |
| `ANA_CONTRAS` | `medcard_profile` | `ana_contras` |
| `ANA_GYNO` | `medcard_profile` | `ana_gyno` |
| `ANA_ALERG` | `medcard_profile` | `ana_alerg` |
| `OBJ_SKIN` / `OBJ_SLIZ` / `OBJ_NOGOT` | `medcard_profile` | `obj_skin` / `obj_sliz` / `obj_nogot` |
| blood / height / weight / eyes / hairs | `medcard_profile` | `blood_group` + `rh_factor` / `height_cm` / `weight_kg` / `eyes` / `hairs` |
| `WORK_PLACE` / `WORK_POSITION` / `DISPANSER` | `medcard_profile` | `work_place` / `work_position` / `dispensary` |
| — (025/у items 7–16) | `medcard_profile` | `oms_policy`, `snils`, `insurer`, `benefit_code`, `marital_status`, `education`, `employment`, `disability` |
| `JAL` | `medcard_visit` | `complaints` |
| `ANA_GEN` | `medcard_visit` | `ana_gen` |
| `ANA_OBJ` | `medcard_visit` | `ana_obj` |
| `DIA_CODE` | `medcard_visit` | `dia_codes` |
| `RECOMENDS` | `medcard_visit` | `recommends` |
| `ID_DOCTOR` | `medcard_visit` | `doctor_id` |
| `base_docs` + `base_docs_datas` | `consent` / `contract` / `check` | template files via `bin/docs-export-legacy.php` → import |
| `base_card_logs` | `medcard_dossier` | instances |

## Human UI

- `/documents` — instances (filters: kind, client, event, status)
- `/documents/{id}/edit` — form generated from schema; proposals panel; print / new revision / void
- `/documents/defs` — definitions; `/documents/defs/{id}/edit` — meta + constructor (fields builder, schema JSON, print HTML, placeholders)
- `/documents/clients/{id}/profile` — open current medcard profile; `/documents/clients/{id}/medcard` — dossier print
- `/documents/settings` — `qr_archive` (superadmin)
- Calendar event edit — "Documents" section (`EVT_DOC_LINKS`); client profile — medcard links; procedure edit — visit sheet link

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (also exposed to the in-system assistant; write ops go through Confirm/Cancel).

Editing templates on request: `docs.defs.get` (current `schema_json` + `print_html`) → `docs.defs.update` with only the changed keys (JSON requests merge with the current values; `schema_json` may be an object). Changed schema/HTML creates a new version; existing documents keep theirs. Check the result with `docs.defs.sample`. `docs.settings` is partial too (`qr_archive`, `org[key]`).

## Disable / uninstall

- `module disable docs` — routes, menu and hook listeners disappear; calendar / clients / procedures pages render without the docs sections; tables and `cfg/modules/docs.ini` stay.
- `module uninstall docs --confirm` — dumps and drops only the owned `base_doc_*` tables (`uninstall.strategy=drop_owned`). Nothing is written into other modules' tables, so nothing is left there. Remaining by design: code + template library files, `cfg/modules/docs.ini`, the generic hooks other modules emit (`calendar.event.saved`, `procedures.edit.sections`, `audio_llm.study.result`) with no listener.
