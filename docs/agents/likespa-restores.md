# Procedure restores (LikeSpa)

Instance feature in module `likespa`: port of oper `show_procedure_restores` / `edit_procedure_restore`.

Schema `base_procedure_restores` stays owned by `procedures`. UI + автовзаиморасчёт live in likespa.

## Enable

- `[modules] likespa=1` and `procedures=1` (soft: `payments=1` for settlement).
- Ini: `cfg/modules/likespa.ini` — `restores_enabled=1`, `restores_lookback_days=4`, `restores_payment_type=1`.
- Permission: `likespa.restores`. Bulk delete / act signature: `SUPERADMIN_ACCESS`.

## Human UI

- `/likespa/restores` — list (menu under procedures group)
- `/likespa/restores/new` — create (cancelled procs: `ENABLED<1`, `SUMM=0`, `SUMM_DEFAULT>0`; window = today, or today−N days for superadmin)
- `/likespa/restores/{id}/edit` — edit reason/summ/sign

On save:

1. Set `ID_OPERATOR_PAID` (operator, or admin if `NOT_IN_TEAM`)
2. If payments on and no row with `DESCR = procedureId` → create staff debit `DIRECTION=-1`, **`ACCOUNT=0`** (internal settlement, no DPS)
3. `ProceduresRepository::applyRestore` → `RESTORED=1`, `SUMM=SUMM_DEFAULT`, `ENABLED=1`

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/likespa/restores?format=json'
curl -sS -b jar.txt 'https://example/likespa/restores/new?format=json'
curl -sS -b jar.txt -X POST 'https://example/likespa/restores/new?format=json' \
  -d 'reason=test&summ=500&id_procedure=123'
curl -sS -b jar.txt 'https://example/likespa/restores/1/edit?format=json'
```

## Notes

- No auto-DPS / ledger moves for these payments.
- Deleting a restore row does not reverse the payment or procedure flags.
