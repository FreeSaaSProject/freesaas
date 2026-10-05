# Agent runbooks

Operation-first guides (not project history). Platform standard: [`../agent-native-audit.md`](../agent-native-audit.md). Hard rules: [`../../AGENTS.md`](../../AGENTS.md).

| Runbook | When |
|---------|------|
| [install.md](install.md) | Greenfield / shared-host bootstrap (first admin + token + migrate) |
| [create-module.md](create-module.md) | New product module |
| [modify-module.md](modify-module.md) | Change existing module / field / EventBus |
| [migrations.md](migrations.md) | Schema / data migrators |
| [localization.md](localization.md) | New locale |
| [remote-api.md](remote-api.md) | Shared hosting: HTTPS `/agent/*` + token |
| [debugging.md](debugging.md) | Health, blocked modules, workers |

Canonical CLI: `php bin/freesaas.php … --format=json`. First bring-up: `bootstrap` or `POST /agent/bootstrap`. HTTP twin: `/agent/*` (see remote-api). Smoke: `php bin/smoke.php`.
