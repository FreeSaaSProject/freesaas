# FreeSaaS

**Open-source business operating system designed to be installed and operated by AI agents.**

Modular PHP platform: enable CRM, shifts, finance, booking, banks, audio analytics, and more as filesystem modules. Agents install, configure, verify, and extend the system via CLI, `/agent/*`, and MCP — without browser automation.

- **Website:** [freesaas.ru](https://freesaas.ru) ([English](https://freesaas.ru/en/))
- **Live demo:** [demoru.freesaas.ru](https://demoru.freesaas.ru) (Russian) · [demoen.freesaas.ru](https://demoen.freesaas.ru) (English) — opens already signed in, read-only, demo data is reset regularly
- **Contacts:** [msms2003@mail.ru](mailto:msms2003@mail.ru) · Telegram [@starcomm](https://t.me/starcomm) · bugs and feature requests — [GitHub Issues](https://github.com/FreeSaaSProject/freesaas/issues/new/choose)

Human architecture guide: [docs/GUIDE.md](docs/GUIDE.md).  
Agent rules: [AGENTS.md](AGENTS.md). Quick path: [docs/agents/QUICKSTART.md](docs/agents/QUICKSTART.md).

## Agent TL;DR (≈2 minutes after DB + config)

```bash
cp cfg/config.example.ini cfg/config.ini   # set DB + salts
php bin/freesaas.php bootstrap --login=admin --password='…' --agent-token='…' --format=json
php bin/freesaas.php health --format=json
php bin/smoke.php --format=json
```

Shared hosting (no SSH): pre-set `app.agent_token` (or `var/setup.key`), then `POST /agent/bootstrap` — [docs/agents/install.md](docs/agents/install.md).  
Ops twin: CLI or `/agent/*` with `X-FreeSaaS-Agent-Token` (no anonymous virgin HTTP).  
Product tools (CRM ops): session JSON or MCP `/llm/mcp` + OAuth — [docs/agents/agent-surfaces.md](docs/agents/agent-surfaces.md).

## Docs

| Doc | Audience |
|-----|----------|
| [docs/GUIDE.md](docs/GUIDE.md) | Humans — architecture, modules |
| [AGENTS.md](AGENTS.md) | Coding agents — hard rules |
| [docs/agents/QUICKSTART.md](docs/agents/QUICKSTART.md) | Fast install + verify |
| [docs/agents/](docs/agents/) | Runbooks (install, MCP, create module, debug, cases) |
| [docs/agent-native-audit.md](docs/agent-native-audit.md) | Agent-readiness standard |
| [docs/TABLE_MAP.md](docs/TABLE_MAP.md) | `base_*` table ownership |

## Quick start (detail)

1. Copy `cfg/config.example.ini` → `cfg/config.ini` (DB credentials, salts).
2. Module secrets: copy `modules/<id>/config.example.ini` → `cfg/modules/<id>.ini` when needed (gitignored).
3. Point vhost docroot at this folder (or use [Docker Compose](docker-compose.yml)).
4. Bootstrap or migrate:

```bash
# Greenfield
php bin/freesaas.php bootstrap --login=admin --password='…' --agent-token='…' --format=json

# Existing DB
php bin/freesaas.php migrate run
# optional: php bin/data-migrate.php
php bin/smoke.php --format=json
```

5. Sign in at `/login`. Diagnose anytime: `php bin/freesaas.php doctor --format=json`.

## Rules

- Runtime tables: `base_*` only.
- Modules live in `modules/<id>/` with `module.json`.

## Contacts

| Topic | Channel |
|-------|---------|
| General questions, partnership | [msms2003@mail.ru](mailto:msms2003@mail.ru) |
| Implementation | [msms2003@mail.ru](mailto:msms2003@mail.ru) or Telegram [@starcomm](https://t.me/starcomm) |
| Bugs and feature requests | [GitHub Issues](https://github.com/FreeSaaSProject/freesaas/issues/new/choose) |
| Security vulnerabilities | [SECURITY.md](SECURITY.md) — private report or email; not public Issues |

More: [freesaas.ru/contacts](https://freesaas.ru/contacts/).

## Licensing

FreeSaaS is dual-licensed:

- **Open source:** [GNU Affero General Public License v3 or later](LICENSE) (`AGPL-3.0-or-later`).
- **Commercial:** optional license from the copyright holder for use, modification, integration, or distribution without AGPL obligations — see [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md).

Copyright © 2026 Ilya Kleshnin. “FreeSaaS” is the project name, not a separate legal entity.

External contributions require accepting the [CLA](CLA.md) — see [CONTRIBUTING.md](CONTRIBUTING.md). Third-party components and their licenses: [THIRD_PARTY.md](THIRD_PARTY.md).

Launch / distribution notes for maintainers: [docs/LAUNCH.md](docs/LAUNCH.md). Agent upstream path: [docs/agents/upstream.md](docs/agents/upstream.md).
