# Authoring contract (stable for agents)

Machine path to add product capability without inventing a second framework.

```bash
php bin/freesaas.php module create <id> --title='…' --format=json
# edit modules/<id>/ (tables base_*, i18n, EventBus, agent.ops)
php bin/freesaas.php module add-migration <id> <slug> --format=json   # as needed
php bin/freesaas.php module enable <id> --accept-risk --format=json
php bin/freesaas.php module validate <id> --format=json
php bin/freesaas.php health --module=<id> --format=json
php bin/freesaas.php module bump <id> --patch --format=json
php bin/build-modules-catalog.php   # monorepo remote install index
php bin/freesaas.php doctor --format=json
```

Inspect shape: `module inspect <id>` / `GET /agent/modules/{id}` — maturity, requires/`optionalRequires`/`soft_deps`, `agent_ops`, `tables`, `hooks`, `bulk_actions` (manifest+runtime), `capability_groups`, `runtime_routes`.

Register MCP tools: add `module.json` → `agent.ops[]` + `AgentHttp::emit` on the page (auto-discovered). No separate MCP registry API.

Rules: [`../../AGENTS.md`](../../AGENTS.md). Surfaces: [agent-surfaces.md](agent-surfaces.md).
