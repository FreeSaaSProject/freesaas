# FreeSaaS (likespa instance)

New modular Core next to legacy `oper.likespa.hm`.

## Docs

- [Руководство для людей](docs/GUIDE.md) — архитектура, структура, как добавлять модули
- [Инструкции для агентов](AGENTS.md) — жёсткие правила и чеклисты для AI
- [Legacy → base_* map](docs/LEGACY_TABLE_MAP.md)

## Quick start

1. Copy `cfg/config.example.ini` → `cfg/config.ini` (already present for local).
2. For module settings (audio/calendar/ledger/…): copy `modules/<id>/config.example.ini` → `cfg/modules/<id>.ini` (secrets stay out of git).
3. Point vhost docroot at this folder.
4. Run schema + data migrators:

```bash
php bin/schema-migrate.php
php bin/data-migrate.php
```

Or open `/migrate` after first deploy (login may need data import first — run CLI).

5. Sign in with an oper staff login (passwords use the same site salt).

## Rules

- FreeSaaS code must not query `phpshop_*`.
- New tables: `base_*`.
- Modules live in `modules/<id>/` with `module.json`.
