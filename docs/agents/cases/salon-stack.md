# Case: Salon stack (CRM + shop + booking)

**Goal:** Enable the storefront/salon combo: clients, catalog, orders, booking, sites.

## Preconditions

- Bootstrapped FreeSaaS (`doctor` without `db_connect_fail` / `config_missing`)
- Alpha/beta modules: pass `--accept-risk` / `accept_risk=1`

## Sequence

```bash
for m in clients catalog orders booking sites; do
  php bin/freesaas.php module enable "$m" --accept-risk --format=json
done

php bin/freesaas.php module list --status=enabled --format=json
php bin/freesaas.php health --format=json
php bin/freesaas.php doctor --format=json
```

Optional storefront host: configure `base_site_hosts` via Sites UI or agent JSON — [sites.md](../sites.md), [booking.md](../booking.md).

## Expected

- Each `module enable` → `ok` (deps auto-checked; enable fails if hard `requires` missing)
- `health.status` ≠ `fail`
- `doctor` without `module_blocked` / `schema_pending`
- Booking/sites remain **alpha** — agent_note may warn; expected with risk ack

## Failure →

[repair.md](../repair.md), [sites.md](../sites.md), [booking.md](../booking.md)
