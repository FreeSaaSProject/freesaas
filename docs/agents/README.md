# Agent runbooks

Operation-first guides (not project history). Platform standard: [`../agent-native-audit.md`](../agent-native-audit.md). Hard rules: [`../../AGENTS.md`](../../AGENTS.md).

| Runbook | When |
|---------|------|
| [install.md](install.md) | Greenfield / shared-host bootstrap (first admin + token + migrate) |
| [create-module.md](create-module.md) | New product module |
| [modify-module.md](modify-module.md) | Change existing module / field / EventBus |
| [migrations.md](migrations.md) | Schema / data migrators |
| [db-backup.md](db-backup.md) | DB hub: dump/restore/maintenance (`/db`, owned for agents) |
| [localization.md](localization.md) | New locale |
| [remote-api.md](remote-api.md) | Shared hosting: HTTPS `/agent/*` + token |
| [module-agent-api.md](module-agent-api.md) | Product JSON ops (`module.json` → `agent.ops` on all modules; session auth) |
| [assistant.md](assistant.md) | In-system LLM harness (UI + bot, tools, confirm writes) |
| [audio-summaries.md](audio-summaries.md) | Audio summary profiles: construct + run via JSON |
| [llm.md](llm.md) | LLM tokens, OAuth/MCP adapter, chat op, audio studies |
| [clients.md](clients.md) | Client CRM: list/get/create/update + statuses/tags JSON |
| [bonuses.md](bonuses.md) | Client loyalty ledger + event apply (JSON) |
| [ledger.md](ledger.md) | Company accounts / ДПС / recon / promised payments (JSON) |
| [bots.md](bots.md) | Telegram/Max transport, queue, webhooks, BotPlugins |
| [likespa-restores.md](likespa-restores.md) | Procedure restores + settlement payment (ACCOUNT=0, JSON) |
| [terminals.md](terminals.md) | Terminals/therms: registry, occupancy, sync jobs + JSON |
| [yclients.md](yclients.md) | YClients pull: settings tokens, session ping, clients import JSON |
| [clientics.md](clientics.md) | Clientics pull: settings tokens, session ping, day import, webhook |
| [debugging.md](debugging.md) | Health, blocked modules, workers |

Canonical CLI: `php bin/freesaas.php … --format=json`. First bring-up: `bootstrap` or `POST /agent/bootstrap`. HTTP twin: `/agent/*` (see remote-api). Smoke: `php bin/smoke.php`.
