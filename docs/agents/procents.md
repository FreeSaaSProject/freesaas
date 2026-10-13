# Commission accruals

Product module `procents`: Enable for commission accrual reporting. Requires rates + procedures + workweeks. No edit UI — reporting only. Use after rates are filled for the period. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable procents --accept-risk --format=json
```

Or `[modules] procents=1` in `cfg/config.ini`.

Permissions: `procents.view`.

## Dependencies

- Requires: `core` >=0.3.0, `rates` >=0.1.0, `procedures` >=0.1.0, `workweeks` >=0.1.0

## Human UI

- `/procents` — read-only commission accrual report.

| Path | Permission |
|------|------------|
| `/procents` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (1 ops).

| Op | Method | Path |
|----|--------|------|
| `procents.report` | GET | `/procents` |

```bash
curl -sS -b jar.txt 'https://example/procents?format=json'
```

## Notes

- Requires `rates` + `procedures` + `workweeks`.
