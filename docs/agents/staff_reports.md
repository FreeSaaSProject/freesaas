# Staff reports

Product module `staff_reports`: Enable for daily staff quality scoring. Requires audio, audio_dicts, comments, procedures. Soft audio_reports/pipeline/contracts/audio_llm. Generate via /staff-reports/generate or Re-® flows. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable staff_reports --accept-risk --format=json
```

Or `[modules] staff_reports=1` in `cfg/config.ini`.

Permissions: `staff_reports.view`, `staff_reports.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `audio` >=0.1.0, `audio_dicts` >=0.1.0, `comments` >=0.1.0, `procedures` >=0.1.0
- Soft: `audio_reports` >=0.2.0, `audio_pipeline` >=0.1.0, `contracts` >=0.1.0, `audio_llm` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_staff_report_profiles` | Owned by `staff_reports` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/staff-reports/generate` — generate day quality reports (traffic lights).
- `/staff-reports/day` — read published day reports.
- `/staff-reports/profiles` — profiles by position (superadmin save).
- Rewards markers also on `/audio/calendar` when audio_reports on.

| Path | Permission |
|------|------------|
| `/staff-reports/generate` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (6 ops).

| Op | Method | Path |
|----|--------|------|
| `staff_reports.generate` | GET | `/staff-reports/generate` |
| `staff_reports.day` | GET | `/staff-reports/day` |
| `staff_reports.profiles.list` | GET | `/staff-reports/profiles` |
| `staff_reports.profiles.get` | GET | `/staff-reports/profiles` |
| `staff_reports.profiles.save` | POST | `/staff-reports/profiles` |
| `staff_reports.rewards` | GET | `/staff-reports/rewards` |

```bash
curl -sS -b jar.txt 'https://example/staff-reports/generate?format=json'
curl -sS -b jar.txt 'https://example/staff-reports/day?format=json'
```

## Notes

- Requires audio + audio_dicts + comments + procedures.
