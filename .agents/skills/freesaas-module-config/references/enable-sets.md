# Suggested enable sets

Always re-check `module inspect` / `modules-catalog.json` for current `requires` and maturity.

| Goal | Enable (order flexible; CLI resolves hard deps) | Risk |
|------|--------------------------------------------------|------|
| Staff day shifts | `workweeks` (+ `calendar` for day UI) | usually release |
| Salon CRM + shop + booking | `clients`, `catalog`, `orders`, `booking`, `sites` | booking/sites may be alpha — `--accept-risk` |
| Ledger + bank sync | `ledger`, then `banks` | banks often beta |
| Conversation QA | `audio`, `asr`, `audio_dicts`, `audio_pipeline`, `audio_reports` | release; LLM studies: `llm` + `audio_llm` (beta) |
| Staff product MCP | `llm`, `assistant` | OAuth `/llm/mcp` per instance |
| Client public MCP | `sites`, `clients`, `client_mcp` (+ often booking/catalog) | `client_mcp` alpha |

Disable leaves tables; uninstall DROP only exclusively owned `tables[]` — see AGENTS.md lifecycle.

Cases with expected doctor/smoke fragments: `docs/agents/cases/`.
