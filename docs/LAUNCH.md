# Launch narrative (distribution through agents)

**Tagline:** Open-source business operating system designed to be installed and operated by AI agents.

## One-paragraph pitch

FreeSaaS is a modular PHP business OS (CRM, shifts, finance, booking, banks, audio analytics, …) that coding agents can **install, diagnose, extend, and operate** via CLI, HTTPS `/agent/*`, and product MCP/OAuth — without browser automation. Dual-licensed AGPL-3.0-or-later + optional commercial terms. Agents that hit a bug are expected to repair locally and upstream a PR (CLA required).

**What Max already does for staff:** natural-language ops in [docs/agents/showcase/](agents/showcase/) (inbound receipt, sick cover, vacation move, shift fill, calendar blocks, …). Reproducible CLI install benchmarks stay in [docs/agents/cases/](agents/cases/).

## Where to post (when ready)

1. **GitHub Discussions** — category “Agent install reports”: paste `doctor` JSON + case name from `docs/agents/cases/`.
2. **Hacker News / Reddit / dev.to** — lead with a reproducible case (“agent installs FreeSaaS + enables workweeks in N minutes”), not ERP screenshots. Link README + [docs/agents/QUICKSTART.md](agents/QUICKSTART.md) + [docs/agents/connect-mcp.md](agents/connect-mcp.md). Point at [showcase/](agents/showcase/) for “what staff already ask Max in chat”.
3. **MCP directories** — list Streamable HTTP endpoint pattern `https://<instance>/llm/mcp` (requires a public demo host). See [docs/agents/mcp-catalog.md](agents/mcp-catalog.md).

Do not chase stars; chase agent transcripts and PRs.

## Integrators / OEM

After agent stickiness exists: sell trust and support via [COMMERCIAL-LICENSE.md](../COMMERCIAL-LICENSE.md) (non-AGPL). Technical work stays agent-operated on the OSS path.
