# Case: Bank connect (ledger + banks)

**Goal:** Enable company finance ledger and banks module; reach settings/OAuth readiness (no live bank credentials in CI).

## Preconditions

- Bootstrapped FreeSaaS
- Superadmin / director for bank OAuth screens (`SUPERADMIN_ACCESS`)

## Sequence

```bash
php bin/freesaas.php module enable ledger --accept-risk --format=json
php bin/freesaas.php module enable banks --accept-risk --format=json
php bin/freesaas.php migrate run --module=banks --format=json
php bin/freesaas.php health --module=banks --format=json
php bin/freesaas.php doctor --format=json
```

Human/agent follow-up for real bank tokens: [banks.md](../banks.md) (cfg/modules/banks.ini, OAuth in UI).

## Expected

- `ledger` + `banks` enabled
- `health --module=banks` ≠ `fail` (missing bank tokens may be `degraded`, not hard fail)
- No `schema_pending` after migrate

## Failure →

[repair.md](../repair.md), [banks.md](../banks.md), [ledger.md](../ledger.md)
