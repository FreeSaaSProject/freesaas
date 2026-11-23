# Acquisition (soft)

First-touch promo sources + inquiry/call journal. Enable only when the instance needs channel markup on clients and a 100% inquiry log (ПРИКАЗ-style). With the module off, `clients` / `calendar` / `ledger` show no source/call controls.

## Owned tables

- `base_promo_sources` — channels (`SRC_ENABLED` for client picker)
- `base_calls` — SoT for inquiries (phone may have `ID_CLIENT=0`)

Additive (not in `tables[]`): `base_clients.source_id` / `source_descr` / `referred_by_client_id`; `base_account_moves.ID_SOURCE` (ROMI).

## Main flows

| Mode | Entry |
|------|--------|
| No PBX | `/acquisition/calls/new` quick form |
| PBX / webhook | `POST /acquisition/ingest` stub → unmarked queue (`?unmarked=1`) |
| Site/MCP book | `orders.created` → call `result=booked` |
| Calendar new event | `calendar.event.saved` → quiet booked stub |

## Agent ops

See `module.json` → `agent.ops`: calls list/get/save, sources list/get/save, ingest.

## BI

Recipes `admin_funnel`, `romi_by_source` with `requires: ["acquisition", …]` — hidden when module off. Visit KPI without channel lives in `procedures` (`doctor_rebook`, `doctor_avg_check`).
