# Distribution report — Agent Skills MVP

Date: 2026-11-01 (updated after push). Scope: three portable skills under [`.agents/skills/`](../../.agents/skills/).

## Skills created

| Skill | Path | Role |
|-------|------|------|
| `freesaas-discovery` | `.agents/skills/freesaas-discovery/` | Business-task fit / honest no-fit |
| `freesaas-deploy` | `.agents/skills/freesaas-deploy/` | Bootstrap, doctor/smoke, `/agent/*` |
| `freesaas-module-config` | `.agents/skills/freesaas-module-config/` | Enable sets + maturity risk |

```bash
npx skills add FreeSaaSProject/freesaas
# or: npx skills add freesaasproject/freesaas --skill freesaas-discovery
```

## Catalog / channel status

| Площадка | URL | Состояние | Проверка установки | Проверка поиска |
|----------|-----|-----------|--------------------|-----------------|
| GitHub (canonical skills) | https://github.com/FreeSaaSProject/freesaas/tree/main/.agents/skills | Published (`1a77acd` on `main`) | `npx skills add FreeSaaSProject/freesaas --list` → 3 skills | Browse tree OK |
| README install docs | https://github.com/FreeSaaSProject/freesaas#agent-skills | Published | One-liner in README | — |
| skills.sh (repo) | https://skills.sh/freesaasproject/freesaas | Published — 3 skills listed | `npx skills add FreeSaaSProject/freesaas -g -y` OK | Card live; install count may lag |
| skills.sh (discovery skill) | https://skills.sh/freesaasproject/freesaas/freesaas-discovery | Published | Install command on card | `npx skills find freesaas` still weak / lagging vs keyword peers |
| MCP Registry | https://registry.modelcontextprotocol.io/ | Not supported (MVP) | — | — |
| Other skill directories | — | Not supported (MVP) | — | — |

## Validation

| Check | Result |
|-------|--------|
| `php bin/check-agent-skills.php` | OK |
| `npx skills-ref validate` | Valid (all 3) |
| CI | `check-agent-skills.php` in static job |
| Public clone install | OK after push |

## Test A / B

- Test A matrix: [skill-activation-matrix.md](skill-activation-matrix.md)
- Test B (cold web discovery): not claimed; skills catalog ≠ product discoverability

## Remaining owner actions

1. More seed installs from other machines/agents to grow skills.sh install counts and improve `npx skills find`.
2. Optional GitHub topics (`agent-skills`, …).
3. Phase 2 domain skills only if this MVP gets real installs/activation.
4. Commit this report update if desired.

## Recommendations

- Keep skills thin; capability map tracks `modules-catalog.json`.
- Cold discovery: still prioritize `llms.txt` / MCP directory URLs / LAUNCH cases alongside skills.sh.
