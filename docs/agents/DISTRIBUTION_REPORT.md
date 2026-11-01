# Distribution report — Agent Skills MVP

Date: 2026-11-01. Scope: three portable skills under [`.agents/skills/`](../../.agents/skills/), not the full 10-skill TZ.

## Skills created

| Skill | Path | Role |
|-------|------|------|
| `freesaas-discovery` | `.agents/skills/freesaas-discovery/` | Business-task fit / honest no-fit |
| `freesaas-deploy` | `.agents/skills/freesaas-deploy/` | Bootstrap, doctor/smoke, `/agent/*` |
| `freesaas-module-config` | `.agents/skills/freesaas-module-config/` | Enable sets + maturity risk |

Install (after this tree is on the public default branch):

```bash
npx skills add FreeSaaSProject/freesaas
```

Local checkout verified: `npx skills add . --list` → 3 skills; global install to `~/.agents/skills/` succeeded from the local path.

## Catalog / channel status

| Площадка | URL | Состояние | Проверка установки | Проверка поиска |
|----------|-----|-----------|--------------------|-----------------|
| GitHub (canonical skills) | https://github.com/FreeSaaSProject/freesaas/tree/main/.agents/skills | Prepared — tree ready in working copy; public URL live after merge/push to default branch | `npx skills add . --list` OK locally; `FreeSaaSProject/freesaas` remote install pending push | N/A until published |
| README install docs | https://github.com/FreeSaaSProject/freesaas#agent-skills | Prepared — section added in repo | One-liner documented | — |
| skills.sh | https://skills.sh/ | Prepared — listing via install telemetry after public `npx skills add FreeSaaSProject/freesaas` | Local global install done (path source; may not attribute to GitHub owner) | `npx skills find freesaas` — FreeSaaS not listed yet (expected) |
| MCP Registry | https://registry.modelcontextprotocol.io/ | Not supported (MVP) — no global public MCP server; per-instance `/llm/mcp` and storefront `/mcp` only | — | — |
| Other skill directories | — | Not supported (MVP) — skills.sh + GitHub first | — | — |

States: **Published** = live for others; **Submitted** = awaiting review; **Prepared** = ready, owner/push action; **Not supported** = out of MVP; **Failed** = attempt failed.

## Validation

| Check | Result |
|-------|--------|
| `php bin/check-agent-skills.php` | OK (3 skills) |
| `npx skills-ref validate` each skill | Valid |
| CI | `php bin/check-agent-skills.php` added to `.github/workflows/ci.yml` static job |
| Secrets in skills | None (placeholder passwords only) |

## Test A (skills installed) — smoke matrix

Prompt → expected skill mapping: [skill-activation-matrix.md](skill-activation-matrix.md) (14 prompts: fit, deploy/config, partial/non-fit).

Full multi-client LLM runs were not executed in this MVP pass; matrix is the acceptance checklist for manual agent smoke after install.

## Test B (cold discovery)

Not claimed. Without skills + without a FreeSaaS URL, agents rely on web/GitHub. Skills must not be counted as proof of product discoverability.

## Automated

- Frontmatter + relative-link CI (`bin/check-agent-skills.php`)
- Links to existing `modules-catalog.json`, cases, QUICKSTART (no runbook fork)

## Manual / owner follow-up

1. Merge/push `.agents/skills/` to `main` on FreeSaaSProject/freesaas.
2. Seed installs: `npx skills add FreeSaaSProject/freesaas -g -y` from a few machines so skills.sh telemetry indexes the repo.
3. Re-check `npx skills find` for keywords: self-hosted CRM, booking, staff scheduling, FreeSaaS.
4. Optional: GitHub topics (`agent-skills`, `mcp`, …) via repo settings.
5. Phase 2 skills (`finance`, `mcp`, …) only if discovery/deploy get installs/activation.

## Recommendations

- Keep skills thin; regenerate capability awareness from `modules-catalog.json`.
- Higher cold-discovery ROI still: product `llms.txt`, MCP directory URLs in [mcp-catalog.md](mcp-catalog.md), LAUNCH cases.
