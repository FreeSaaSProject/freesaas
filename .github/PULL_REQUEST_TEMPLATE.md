## Summary

<!-- What and why (1–3 bullets). -->

## Checklist

- [ ] `php bin/freesaas.php module validate <id> --format=json` (or `validate` all) — exit 0
- [ ] `php bin/freesaas.php health --format=json` — not `fail`
- [ ] `php bin/smoke.php --format=json` — ok (when platform/schema touched)
- [ ] UI chrome uses `t()` / i18n keys; both `lang/ru.php` and `lang/en.php` updated if needed
- [ ] No secrets committed (`cfg/config.ini`, live `cfg/modules/*.ini`)
- [ ] **CLA accepted** ([CLA.md](../CLA.md) / [CONTRIBUTING.md](../CONTRIBUTING.md)) — required for external contributors

## Module / maturity

- Module id(s):
- Maturity (alpha/beta/release):

## Agent / verify output

<!-- Paste relevant doctor/smoke/health JSON fragments. -->

```json

```
