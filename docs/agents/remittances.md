# Remittances

Agency / acquiring remittance agreements: parse ledger move purpose text, compare partner gross/fee to expected daily revenue. **Settings only** in this module; overview lives on `/ledger/moves`.

Maturity: **beta**.

## Enable

```bash
php bin/freesaas.php module enable remittances --accept-risk --format=json
```

Requires `ledger` + `suppliers`. Soft: `banks`, `procedures`, `likespa` (expected channel `spa_ops`).

Permissions: `remittances.manage` (agreements), `remittances.delete`. Badges/sections need `ledger.view` / actions `ledger.edit`.

## Human UI

| Path | Role |
|------|------|
| `/remittances` | List agreements |
| `/remittances/new`, `/remittances/{id}/edit` | Counterparty (`ID_SUPPLIER` picker), account/type filters, fee %, `fee_posting`, parser preset + editable slot regexes, dry-run |
| `/ledger/moves` | Remittance badges on matching inflows (period · Δ) |
| `/ledger/moves/{id}/edit` | Reconcile section: expected by day, deltas, sync / ignore / post fee |

No separate Excel-style report screen.

## Agreement match

1. Prefer `base_account_moves.ID_SUPPLIER = agreement.ID_SUPPLIER`
2. Else purpose `DESCR` contains supplier INN and/or name (`UR_NAME` / `NAME`)
3. Optional `auto_set_supplier`: fill empty `ID_SUPPLIER` after a successful parse (banks sync often leaves 0)

## Parser slots

Named PCRE slots on the agreement: `period_from`, `period_to`, `gross`, `fee` (capture group 1 each). Preset `volga_termy_agency_v1` matches Volga Termy agency purpose text (period + Сумма + возмещаемых расходов). Apply preset, then edit slots if wording drifts — no code change.

## Expected revenue

EventBus `remittances.day_expected`:

```text
in:  { date_ymd, agreement_id, agreement, channels_filter }
out: { amount, channels: [{ code, amount, source }] }
```

`likespa` (when enabled) appends `spa_ops` = `ProceduresRepository::dayColumnStats` revenue (`RESTORED=0`, exclude `1C_ID=999`). Future POS modules can emit `cash` / `card`; agreement `expected_channels` filters which codes sum.

## Fee posting

`fee_posting=none` — reconcile only.  
`fee_posting=expense_move` — button on move edit creates expense ДПС (idempotent via `base_remittance_batches.ID_FEE_MOVE`).

## Agent JSON

Session auth; `?format=json` / `Accept: application/json`.

```bash
curl -sS -b jar.txt 'https://admin.example/remittances?format=json'
curl -sS -b jar.txt -X POST 'https://admin.example/remittances/new?format=json' \
  -d 'name=Termy&supplier_id=41&parser_preset=volga_termy_agency_v1&fee_percent=2&enabled=1&action=save'
curl -sS -b jar.txt -X POST 'https://admin.example/remittances/moves/6763/action?format=json' \
  -d 'action=sync'
```

## Tables

- `base_remittance_agreements`
- `base_remittance_batches` (one row per `ID_MOVE`)
