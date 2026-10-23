# Health & fitness

Product module `wellness`: personal food, workout and health diary. Can be enabled inside a corporate instance — all data stays personal: every query is scoped to the current user (`WellnessOwner`), with no `SUPERADMIN_ACCESS` bypass. Catalog maturity: `release`.

## Enable

```bash
php bin/freesaas.php module enable wellness --format=json
```

Or `[modules] wellness=1` in `cfg/config.ini`.

Permissions: `wellness.view`, `wellness.edit`.

## Dependencies

- Requires: `core` >=1.0.0
- Soft: `asr` >=1.0.0, `bots` >=1.0.0

## Tables

| Table | Role |
|-------|------|
| `base_fit_foods` | Owned by `wellness` |
| `base_fit_foodlogs` | Owned by `wellness` |
| `base_fitlogs` | Owned by `wellness` |
| `base_fit_targets` | Owned by `wellness` |
| `base_fit_weightlogs` | Owned by `wellness` |
| `base_fit_recipes` | Owned by `wellness` |
| `base_fit_recipe_items` | Owned by `wellness` |
| `base_healths_medicines` | Owned by `wellness` |
| `base_healths_symptoms` | Owned by `wellness` |
| `base_healths_logs` | Owned by `wellness` |
| `base_healths_recipes` | Owned by `wellness` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- Personal wellness: food diary `/wellness`, calendar, workouts, weight, health diary, doctor Rx.
- Dictionaries: foods, targets, medicines, symptoms.
- Soft bots/asr for voice food entry.

| Path | Permission |
|------|------------|
| `/wellness` | `—` |
| `/wellness/calendar` | `—` |
| `/wellness/workouts` | `—` |
| `/wellness/weight` | `—` |
| `/wellness/health-diary` | `—` |
| `/wellness/doctor-rx` | `—` |
| `/wellness/foods` | `—` |
| `/wellness/targets` | `—` |
| `/wellness/medicines` | `—` |
| `/wellness/symptoms` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (37 ops).

| Op | Method | Path |
|----|--------|------|
| `wellness.calendar.month` | GET | `/wellness/calendar` |
| `wellness.food_diary.list` | GET | `/wellness` |
| `wellness.food_diary.get` | GET | `/wellness/food-diary/{id}/edit` |
| `wellness.food_diary.create` | POST | `/wellness/food-diary/new` |
| `wellness.food_diary.update` | POST | `/wellness/food-diary/{id}/edit` |
| `wellness.foods.list` | GET | `/wellness/foods` |
| `wellness.foods.get` | GET | `/wellness/foods/{id}/edit` |
| `wellness.foods.create` | POST | `/wellness/foods/new` |
| `wellness.foods.update` | POST | `/wellness/foods/{id}/edit` |
| `wellness.workouts.list` | GET | `/wellness/workouts` |
| `wellness.workouts.get` | GET | `/wellness/workouts/{id}/edit` |
| `wellness.workouts.create` | POST | `/wellness/workouts/new` |
| `wellness.workouts.update` | POST | `/wellness/workouts/{id}/edit` |
| `wellness.weight.list` | GET | `/wellness/weight` |
| `wellness.weight.get` | GET | `/wellness/weight/{id}/edit` |
| `wellness.weight.create` | POST | `/wellness/weight/new` |
| `wellness.weight.update` | POST | `/wellness/weight/{id}/edit` |
| `wellness.targets.list` | GET | `/wellness/targets` |
| `wellness.targets.get` | GET | `/wellness/targets/{id}/edit` |
| `wellness.targets.create` | POST | `/wellness/targets/new` |
| `wellness.targets.update` | POST | `/wellness/targets/{id}/edit` |
| `wellness.health_diary.list` | GET | `/wellness/health-diary` |
| `wellness.health_diary.get` | GET | `/wellness/health-diary/{id}/edit` |
| `wellness.health_diary.create` | POST | `/wellness/health-diary/new` |
| `wellness.health_diary.update` | POST | `/wellness/health-diary/{id}/edit` |
| `wellness.doctor_rx.list` | GET | `/wellness/doctor-rx` |
| `wellness.doctor_rx.get` | GET | `/wellness/doctor-rx/{id}/edit` |
| `wellness.doctor_rx.create` | POST | `/wellness/doctor-rx/new` |
| `wellness.doctor_rx.update` | POST | `/wellness/doctor-rx/{id}/edit` |
| `wellness.medicines.list` | GET | `/wellness/medicines` |
| `wellness.medicines.get` | GET | `/wellness/medicines/{id}/edit` |
| `wellness.medicines.create` | POST | `/wellness/medicines/new` |
| `wellness.medicines.update` | POST | `/wellness/medicines/{id}/edit` |
| `wellness.symptoms.list` | GET | `/wellness/symptoms` |
| `wellness.symptoms.get` | GET | `/wellness/symptoms/{id}/edit` |
| `wellness.symptoms.create` | POST | `/wellness/symptoms/new` |
| `wellness.symptoms.update` | POST | `/wellness/symptoms/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/wellness/calendar?format=json'
curl -sS -b jar.txt 'https://example/wellness?format=json'
```

## Notes

- Data is per current staff user for diary ops.
- Assistant food flow: `wellness.foods.list(q)` → `wellness.food_diary.create`.
