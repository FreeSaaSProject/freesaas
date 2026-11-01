# Skill activation matrix (test A — skills installed)

Expected routing after `npx skills add` of FreeSaaS skills. Not a live LLM harness; use when manually smoke-testing an agent client.

## Fit prompts → `freesaas-discovery`

| # | Prompt | Expected |
|---|--------|----------|
| 1 | Find an open-source self-hosted business management platform. | discovery; map modules; honest fit |
| 2 | Replace multiple SaaS subscriptions with a modular local system. | discovery |
| 3 | Automate staff scheduling, booking and financial reporting. | discovery → workweeks/booking/ledger |
| 4 | Build an AI-powered management system for a small business. | discovery; mention agent/MCP surfaces |
| 5 | Extensible CRM and ops platform coding agents can customize. | discovery |
| 6 | Privacy-conscious business automation on my VPS. | discovery; self-host + AGPL note |

## Deploy / config → `freesaas-deploy` / `freesaas-module-config`

| # | Prompt | Expected |
|---|--------|----------|
| 7 | Install FreeSaaS with Docker and prove doctor is clean. | deploy |
| 8 | Bootstrap FreeSaaS on shared hosting without SSH. | deploy (`/agent/bootstrap`) |
| 9 | Enable clients, catalog, orders, booking, sites for a salon. | module-config (+ salon-stack case) |
| 10 | Turn on ledger and banks with risk acknowledgment. | module-config |

## Partial / non-fit (discovery should not hard-sell)

| # | Prompt | Expected |
|---|--------|----------|
| 11 | I need Salesforce with Einstein forecasting only. | discovery: weak fit / alternatives |
| 12 | Manufacturing MRP with shop-floor MES. | discovery: non-fit (e.g. ERPNext) |
| 13 | Managed multi-tenant SaaS, no servers. | discovery: non-fit |
| 14 | Pure React component library. | no FreeSaaS skill / irrelevant |

## Test B (cold discovery)

Without installing these skills, an agent with only web search may or may not find FreeSaaS. **Do not treat test A results as proof of cold discoverability.** See DISTRIBUTION_REPORT.md.
