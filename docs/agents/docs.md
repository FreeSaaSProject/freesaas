# Docs (documents + medical cards)

Product module `docs`: one engine for printable documents (consents, checks) **and** medical cards. Catalog maturity: `alpha`.

URL prefix is `/documents` (not `/docs`: `.htaccess` forbids `^docs/` because the repo's `docs/` folder must not be served). Module id, permissions (`docs.*`) and tables (`base_doc_*`) keep the short name.

Medcard is not a separate module: it is a set of document **kinds** over the same definitions / instances / print pipeline. Legacy elos/oper had two stacks (`base_docs*` + `printdoc.php` for consents; hardcoded `getCardData()` 025/u over `phpshop_shopusers` + `base_procedures` columns for medcards). FreeSaaS keeps clinical data **only** in `base_doc_*` — never as columns on `base_clients` / `base_procedures`.

## Enable

```bash
php bin/freesaas.php module enable docs --accept-risk --format=json
php bin/freesaas.php migrate run --module=docs --format=json
cp modules/docs/config.example.ini cfg/modules/docs.ini   # optional: qr_archive
```

Default definitions (`medcard_profile`, `medcard_visit`, `medcard_dossier`, sample `consent`) are seeded on first open of `/documents` (runtime, current locale).

Permissions: `docs.view`, `docs.edit`, `docs.print`, `docs.medical` (medcard kinds), `docs.manage` (definitions/templates), `docs.sign` (QR archive sign). Calendar section needs role flag `EVT_DOC_LINKS` (or superadmin).

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
| `medcard_profile` | client (one current) | Standing anamnesis / objective status (legacy `phpshop_shopusers.ANA_*`, `OBJ_*`) |
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
| `{dossier.diagnoses}` `{dossier.visits}` `{dossier.procedures}` | 025/у item 20 table, all visit sheets (each with its own version), medical procedures list |
| `{dossier}` | (`medcard_dossier` only) legacy profile + visit sheets list |
| `{qr}` | Archive QR (empty when `qr_archive=0`) |

Seed templates follow form 025/у (приказ Минздрава № 834н) as printed by legacy `getCardData`: `medcard_profile` = title page (items 1–23), `medcard_visit` = item 24 (first, B01.008.003) / 25 (follow-up, B01.008.004), `medcard_dossier` = title + all visits + procedures sheet + items 26–35. Untouched seeds are upgraded to the current seed revision automatically (`docs.seed_revision`); edited definitions are left alone.

## Template library and samples

Templates (definitions, never instances) can live as files instead of DB rows:

- `modules/docs/library/<pack>/<code>.json` — format `freesaas.doc-template` v1 (`code`, `kind`, `name`, `scope`, trigger, `expire_days`, `orientation`, `paper`, `copies`, `schema`, `print_html`, `source`); `pack.json` describes the pack.
- `/documents/defs/import` — pick templates from a pack (or upload a file / array of files); options: keep service/type id triggers (same DB only — otherwise `procs`/`types` become `manual`), update existing (new version), enable. Imported definitions start disabled.
- `/documents/defs/{id}/export` — download one definition as a template file.
- `/documents/defs/{id}/sample[?version=<version id>]` and `/documents/defs/import/sample?pack=&file=` — print a sample with demo data (watermark, nothing stored). Organization requisites use the real settings where filled, demo values otherwise.

### Legacy export (elos / oper)

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

| Legacy column | Where | Kind | Field id |
|---------------|-------|------|----------|
| `ANA_LIFE` | `phpshop_shopusers` | `medcard_profile` | `ana_life` |
| `ANA_CONTRAS` | `phpshop_shopusers` | `medcard_profile` | `ana_contras` |
| `ANA_GYNO` | `phpshop_shopusers` | `medcard_profile` | `ana_gyno` |
| `ANA_ALERG` | `phpshop_shopusers` | `medcard_profile` | `ana_alerg` |
| `OBJ_SKIN` / `OBJ_SLIZ` / `OBJ_NOGOT` | `phpshop_shopusers` | `medcard_profile` | `obj_skin` / `obj_sliz` / `obj_nogot` |
| blood / height / weight / eyes / hairs | `phpshop_shopusers` | `medcard_profile` | `blood_group` + `rh_factor` / `height_cm` / `weight_kg` / `eyes` / `hairs` |
| `WORK_PLACE` / `WORK_POSITION` / `DISPANSER` | `phpshop_shopusers` | `medcard_profile` | `work_place` / `work_position` / `dispensary` |
| — (025/у items 7–16) | — | `medcard_profile` | `oms_policy`, `snils`, `insurer`, `benefit_code`, `marital_status`, `education`, `employment`, `disability` |
| `JAL` | `base_procedures` | `medcard_visit` | `complaints` |
| `ANA_GEN` | `base_procedures` | `medcard_visit` | `ana_gen` |
| `ANA_OBJ` | `base_procedures` | `medcard_visit` | `ana_obj` |
| `DIA_CODE` | `base_procedures` | `medcard_visit` | `dia_codes` |
| `RECOMENDS` | `base_procedures` | `medcard_visit` | `recommends` |
| `ID_DOCTOR` | `base_procedures` | `medcard_visit` | `doctor_id` |
| `base_docs` + `base_docs_datas` | docs | `consent` / `contract` / `check` | template files via `bin/docs-export-legacy.php` → import |
| `base_card_logs` | print journal | `medcard_dossier` | instances |

Optional migrator `legacy_docs_medcard` (run explicitly) copies medical card data that exists: `phpshop_shopusers.ANA_*`/`OBJ_*` → `medcard_profile`, medical `base_procedures` rows with `ANA_*` → `medcard_visit`. Columns are probed; missing sources are skipped. Templates are not copied by the migrator — use the library (below).

```bash
php bin/data-migrate.php --module=docs --id=legacy_docs_medcard
```

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
