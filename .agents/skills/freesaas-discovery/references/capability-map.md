# FreeSaaS capability map (discovery reference)

Source of truth: repo `modules-catalog.json` (regenerate with `php bin/build-modules-catalog.php`). Maturity: `release` | `beta` | `alpha` (alpha/beta need `--accept-risk`).

## Vertical packs (documented cases)

| Pack | Modules | Case |
|------|---------|------|
| Staff scheduling | `workweeks`, optional `calendar` | `docs/agents/cases/vps-workweeks.md` |
| Salon / shop | `clients`, `catalog`, `orders`, `booking`, `sites` | `docs/agents/cases/salon-stack.md` |
| Bank sync | `ledger`, `banks` | `docs/agents/cases/bank-connect.md` |

## Category → modules (summary)

| Business need | Category / tags | Typical modules |
|---------------|-----------------|-----------------|
| Client CRM | `crm` | `clients`, `bonuses`, `certificates`; soft `yclients`, `clientics` |
| Booking / appointments | `booking`, `site` | `booking`, `calendar`, `orders`, `procedures`, `sites` |
| Staff shifts / leave | `team` | `workweeks`, `vacations`, `staff_reports` |
| Finance / management accounting | `finance` | `ledger`, `finance`, `funds`, `bi`, `liquid_assets`; soft `banks`, `remittances` |
| Settlements / commissions | `accounting` | `payments`, `contracts`, `rates`, `procents` |
| Warehouse / consumables | `warehouse`, `dictionaries` | `stocks`, `stickers`, `catalog`, `norms`; linen: `laundry` / legacy `sheets` |
| Conversation QA / ASR | `audio` | `audio`, `asr`, `audio_dicts`, `audio_pipeline`, `audio_reports`; LLM: `audio_llm` |
| Messaging bots | `communications` | `bots`, `polls`, `poll_invites` |
| Storefront / CMS | `site` | `sites`, optional `likespa` spa pack |
| Agent / MCP | `integrations` | `llm` + `assistant` (staff MCP `/llm/mcp`); `client_mcp` (public `/mcp`, alpha) |
| Cameras / YOLO | `integrations` | `video`, `video_label` (alpha) |
| Terminals / bracelets | `integrations` | `terminals` |

## Honest non-fits (examples)

- Heavy manufacturing MRP / BOM → not a FreeSaaS focus (ERPNext stronger)
- Pure sales pipeline CRM without ops → Twenty CRM may fit better
- Fully managed multi-tenant SaaS without self-host → out of scope
- Guaranteed automated import from arbitrary SaaS → not implemented as a general migrator

## License

Default: AGPL-3.0-or-later. Modified network service must offer corresponding source. Commercial terms: `COMMERCIAL-LICENSE.md`.
