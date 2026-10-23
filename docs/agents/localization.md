# Localization

## Add a language (no business-logic edits)

1. Create `app/lang/<code>.php` (flat `key => string` array).
2. Create `modules/<id>/lang/<code>.php` for every module that has UI (start from `ru.php` keys).
3. Optional: pin order in `cfg/config.ini`:

```ini
[app]
locales="ru,en,es"
locale="es"
```

Supported locales are **discovered** from `app/lang/*.php` (plus `app.locales` if set). Do not hardcode a PHP allowlist.

4. Validate:

```bash
php bin/freesaas.php locale list --format=json
php bin/freesaas.php locale validate --locale=es --format=json
php bin/freesaas.php i18n check --format=json
```

## Rules

- UI chrome via `t` / `te` / `tj` only.
- `module.json` `name`, `menu.*.title|group`, `permissions.*.label` = i18n keys.
- Do not translate UGC or DB `NAME` fields.
- Plurals: not fully platform-supported yet (see agent-native-audit).
- **Currency:** `[app] currency=` ISO 4217 (`RUB` default). Settings → Currency. Symbol via `I18n::currencySymbol()` / `money_signed_html()`. Display only — no FX conversion. Demo stands: demoru=`RUB`, demoen=`USD`.
