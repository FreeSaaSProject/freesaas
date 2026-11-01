# FreeSaaS agent skills

## Portable Agent Skills (preferred)

Cross-client packages ([agentskills.io](https://agentskills.io/specification)) live in [`.agents/skills/`](../../.agents/skills/):

| Skill | Role |
|-------|------|
| `freesaas-discovery` | Business-task fit assessment |
| `freesaas-deploy` | Install / verify |
| `freesaas-module-config` | Enable module sets |

Install: `npx skills add FreeSaaSProject/freesaas` — see [README](../../README.md#agent-skills) and [DISTRIBUTION_REPORT.md](../agents/DISTRIBUTION_REPORT.md).

## Short in-repo playbooks

Command recipes when you already have the checkout open (also linked from the portable skills):

| Playbook | File |
|----------|------|
| Diagnose install | [diagnose-install.md](diagnose-install.md) |
| Create module | [create-module.md](create-module.md) |
| Add migration | [add-migration.md](add-migration.md) |
| Connect MCP | [connect-mcp.md](connect-mcp.md) |
| Enable module set | [enable-module-set.md](enable-module-set.md) |

Platform rules: [`../../AGENTS.md`](../../AGENTS.md).
