# FreeSaaS (likespa instance)

New modular Core next to legacy `oper.likespa.hm`.

## Docs

- [Руководство для людей](docs/GUIDE.md) — архитектура, структура, как добавлять модули
- [Инструкции для агентов](AGENTS.md) — жёсткие правила и чеклисты для AI
- [Agent-native audit](docs/agent-native-audit.md) — готовность к агентам, требования к системе и модулям
- [Agent runbooks](docs/agents/) — install, create/modify module, migrations, locale, remote API, debug
- [Table ownership map](docs/TABLE_MAP.md)

## Quick start

1. Copy `cfg/config.example.ini` → `cfg/config.ini` (already present for local).
2. For module settings (audio/calendar/ledger/…): copy `modules/<id>/config.example.ini` → `cfg/modules/<id>.ini` (secrets stay out of git).
3. Point vhost docroot at this folder.
4. Bootstrap (schema migrate + first admin + agent token), or migrate-only on an existing DB:

```bash
# Greenfield
php bin/freesaas.php bootstrap --login=admin --password='…' --agent-token='…' --format=json

# Existing DB
php bin/freesaas.php migrate run
php bin/data-migrate.php
# optional: php bin/smoke.php
```

Shared hosting without SSH: `POST /agent/bootstrap` — see [docs/agents/install.md](docs/agents/install.md).

5. Sign in at `/login`. Ops after that: CLI or `/agent/*` with `X-FreeSaaS-Agent-Token`.

## Rules

- Runtime tables: `base_*` only.
- Modules live in `modules/<id>/` with `module.json`.

## Licensing

FreeSaaS is dual-licensed:

- **Open source:** [GNU Affero General Public License v3 or later](LICENSE) (`AGPL-3.0-or-later`).
- **Commercial:** optional license from the copyright holder for use, modification, integration, or distribution without AGPL obligations — see [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md).

Copyright © 2026 Ilya Kleshnin. “FreeSaaS” is the project name, not a separate legal entity.

External contributions require accepting the [CLA](CLA.md) — see [CONTRIBUTING.md](CONTRIBUTING.md). Third-party components and their licenses: [THIRD_PARTY.md](THIRD_PARTY.md).
