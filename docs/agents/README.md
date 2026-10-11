# Agent runbooks

Operation-first guides (not project history). Platform standard: [`../agent-native-audit.md`](../agent-native-audit.md). Hard rules: [`../../AGENTS.md`](../../AGENTS.md). License/CLA: [`../../LICENSE`](../../LICENSE), [`../../CONTRIBUTING.md`](../../CONTRIBUTING.md).

| Runbook | When |
|---------|------|
| [QUICKSTART.md](QUICKSTART.md) | Fast path: config → bootstrap → doctor/smoke |
| [agent-surfaces.md](agent-surfaces.md) | Platform `agent_token` vs product session/MCP/OAuth |
| [connect-mcp.md](connect-mcp.md) | Connect external agents (MCP hub) |
| [connect-chatgpt.md](connect-chatgpt.md) / [connect-codex.md](connect-codex.md) / [connect-cursor.md](connect-cursor.md) / [connect-claude-code.md](connect-claude-code.md) / [connect-gemini.md](connect-gemini.md) | Per-environment 5-minute connect |
| [repair.md](repair.md) | Doctor codes → next command → upstream issue |
| [upstream.md](upstream.md) | Doctor → issue → CLA → PR flywheel |
| [mcp-catalog.md](mcp-catalog.md) | How to list instance MCP in external catalogs |
| [../LAUNCH.md](../LAUNCH.md) | Maintainer launch narrative |
| [install.md](install.md) | Greenfield / shared-host bootstrap (first admin + token + migrate) |
| [sites.md](sites.md) | Public storefront (`site.php`): hosts, themes, cart, **`sites.status` diagnose**, agent JSON |
| [booking.md](booking.md) | Slot engine + advance book into orders (alpha); cart wizard; `/t` later |
| [themes.md](themes.md) | Port legacy skin → `themes/<id>/`, bind host, sync to storefront copy |
| [create-module.md](create-module.md) | New product module |
| [authoring-contract.md](authoring-contract.md) | Stable create → enable → validate → bump → catalog |
| [modify-module.md](modify-module.md) | Change existing module / field / EventBus |
| [migrations.md](migrations.md) | Schema / data migrators |
| [db-backup.md](db-backup.md) | DB hub: dump/restore/maintenance (`/db`, owned for agents) |
| [localization.md](localization.md) | New locale |
| [remote-api.md](remote-api.md) | Shared hosting: HTTPS `/agent/*` + token |
| [update.md](update.md) | Self-update: core + private module zips (`cfg/updates.ini`) |
| [module-agent-api.md](module-agent-api.md) | Product JSON ops (`module.json` → `agent.ops` on all modules; session auth) |
| [assistant.md](assistant.md) | In-system LLM harness (UI + bot, tools, confirm writes) |
| [audio-summaries.md](audio-summaries.md) | Audio summary profiles: construct + run via JSON |
| [llm.md](llm.md) | LLM tokens, OAuth/MCP adapter, chat op, audio studies |
| [clients.md](clients.md) | Client CRM: list/get/create/update + statuses/tags JSON |
| [bonuses.md](bonuses.md) | Client loyalty ledger + event apply (JSON) |
| [ledger.md](ledger.md) | Company accounts / ДПС / recon / promised payments (JSON) |
| [ledger-chart.md](ledger-chart.md) | Agent-led chart seeding (interview, FOT + act_in, no hardcoded tree) |
| [bots.md](bots.md) | Telegram/Max transport, queue, webhooks, BotPlugins; `/agent/bots/*` repair |
| [gcal.md](gcal.md) | Google Calendar sync; `/agent/gcal/*` diagnose/repair |
| [likespa-restores.md](likespa-restores.md) | LikeSpa pack: legacy cutover, restores, FREE_TICKET, settlement JSON |
| [terminals.md](terminals.md) | Terminals/therms: registry, occupancy, sync jobs + JSON |
| [yclients.md](yclients.md) | YClients pull: settings tokens, session ping, clients import JSON |
| [clientics.md](clientics.md) | Clientics pull: settings tokens, session ping, day import, webhook |
| [cron.md](cron.md) | Platform hard cron: `/cron/tick`, `Cron::register`, journal |
| [debugging.md](debugging.md) | Health, blocked modules, workers |
| [cases/](cases/) | Reproducible agent scenario benchmarks |
| [../skills/](../skills/) | Short agent skills (create-module, diagnose, …) |

Canonical CLI: `php bin/freesaas.php … --format=json`. First bring-up: `bootstrap` or `POST /agent/bootstrap`. HTTP twin: `/agent/*` (see remote-api). Diagnose: `doctor`. Smoke: `php bin/smoke.php`.
