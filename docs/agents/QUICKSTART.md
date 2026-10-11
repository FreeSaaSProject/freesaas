# Agent quickstart

Get from empty host to verified FreeSaaS in minutes. Hard rules: [`../../AGENTS.md`](../../AGENTS.md). Full install: [install.md](install.md).

## Prerequisites

- PHP 8.1+ with PDO MySQL, writable `cfg/`
- MySQL/MariaDB database created
- Docroot = repository root (`index.php`, `site.php`)

Optional: [Docker Compose](../../docker-compose.yml) (`docker compose up -d`).

## Three commands

```bash
# 1) Config (edit DB + salts)
cp cfg/config.example.ini cfg/config.ini

# 2) First admin + schema + agent token
php bin/freesaas.php bootstrap \
  --login=admin --password='change-me-now-8+' \
  --agent-token='a-long-random-secret' \
  --format=json

# 3) Prove nothing is broken
php bin/freesaas.php doctor --format=json
# or: php bin/smoke.php --format=json
```

Shared hosting without SSH — **pre-set** `app.agent_token` in `cfg/config.ini` (FTP), then:

```bash
curl -sS -X POST "https://example.com/agent/bootstrap" \
  -H "Content-Type: application/json" \
  -H "X-FreeSaaS-Agent-Token: a-long-random-secret" \
  -d '{"login":"admin","password":"change-me-now-8+"}'
curl -sS -H "X-FreeSaaS-Agent-Token: a-long-random-secret" \
  "https://example.com/agent/doctor"
```

No token in ini yet? Upload `var/setup.key` and use `X-FreeSaaS-Setup-Key` — full steps in [install.md](install.md). There is no anonymous HTTP bootstrap.

## Next

| Goal | Doc |
|------|-----|
| Enable modules | `php bin/freesaas.php module enable <id> --format=json` — [remote-api.md](remote-api.md) |
| Ledger chart empty after enable | Interview + seed — [ledger-chart.md](ledger-chart.md) |
| Product CRM tools / MCP | [agent-surfaces.md](agent-surfaces.md), [connect-mcp.md](connect-mcp.md) |
| New module | [create-module.md](create-module.md) or `module create` |
| Broken install | [repair.md](repair.md), [debugging.md](debugging.md) |
| Scenario benchmarks | [cases/](cases/) |

## Auth surfaces (do not mix)

- **Platform lifecycle** (bootstrap, enable, migrate, update): CLI or `/agent/*` + `X-FreeSaaS-Agent-Token`
- **Product ops** (clients, orders, booking…): staff session JSON / assistant / MCP OAuth — see [agent-surfaces.md](agent-surfaces.md)

## License note (AGPL)

Default license is **AGPL-3.0-or-later** ([`LICENSE`](../../LICENSE)). If you deploy a **modified** copy as a network service, you must offer corresponding source to users of that service. Optional commercial terms: [`COMMERCIAL-LICENSE.md`](../../COMMERCIAL-LICENSE.md). External PRs need a CLA — [`CONTRIBUTING.md`](../../CONTRIBUTING.md).
