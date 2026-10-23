# Vacations

Product module `vacations`: Enable for staff vacation register and approvals. Depends only on core. Team rail (next to shifts); independent of calendar booking. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable vacations --accept-risk --format=json
```

Or `[modules] vacations=1` in `cfg/config.ini`.

Permissions: `vacations.view`, `vacations.edit`.

## Dependencies

- Requires: `core` >=0.3.0

## Tables

| Table | Role |
|-------|------|
| `base_vacations` | Owned by `vacations` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/vacations` — leave register and sign workflow (menu: Team, after Shifts).

| Path | Permission |
|------|------------|
| `/vacations` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `vacations.list` | GET | `/vacations` |
| `vacations.get` | GET | `/vacations/{id}/edit` |
| `vacations.create` | POST | `/vacations/new` |
| `vacations.update` | POST | `/vacations/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/vacations?format=json'
```

## Notes

- List may be scoped to current user unless privileged (`CAN_SEE_SUM_OTHERS`; group drafts with `assistant_groups.group_read_unrestricted=1` read unscoped).
- `vacations.update` is a patch: send `id` + changed fields (`dt_start`/`dt_end` as `YYYY-MM-DD`); omitted fields keep stored values.
- Moving a vacation in the assistant: `workweeks` declares a relation, so shifts inside the new period are added as `workweeks.delete` steps of the same plan (one Confirm; needs `app.agent_allow_delete=1`).
