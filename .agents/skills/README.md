# FreeSaaS Agent Skills

Canonical portable skills ([specification](https://agentskills.io/specification)).

| Skill | Directory |
|-------|-----------|
| Fit assessment | [freesaas-discovery](freesaas-discovery/) |
| Install / verify | [freesaas-deploy](freesaas-deploy/) |
| Enable modules | [freesaas-module-config](freesaas-module-config/) |

```bash
npx skills add FreeSaaSProject/freesaas
npx skills add FreeSaaSProject/freesaas --skill freesaas-discovery
# local checkout:
npx skills add . --list
```

Validate: `php bin/check-agent-skills.php` or `npx skills-ref validate .agents/skills/<name>`.

Do not duplicate these trees under `.cursor/skills` — clients that support `.agents/skills` load this path; the skills CLI copies/symlinks into agent-specific dirs on install.
