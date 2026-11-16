# Showcase — real asks to Max (in-system assistant)

Staff write a sentence in chat (UI `/assistant`, personal bot, or Max **group**). Max reads product data via `agent.ops`, queues **writes as pending drafts**, and a person with rights confirms. These pages are **examples of day-to-day work**, not CLI install benchmarks.

**Names, stocks, suppliers, and products below are fictional** (anonymized for public docs). Shape of the asks matches real production use.

| Collection | Purpose |
|------------|---------|
| **This folder** (`showcase/`) | Natural-language ops Max already handles (or is wired to handle) |
| [`../cases/`](../cases/) | Reproducible platform benchmarks (`bootstrap` / `module enable` / `doctor`) |

Language in-repo is English-only (including Ask quotes).

## Cases

| Case | Staff ask (short) |
|------|-------------------|
| [receipt-inbound.md](receipt-inbound.md) | "Receive into North warehouse… MarketPlus" |
| [sick-cover.md](sick-cover.md) | "Orlova is sick… find a replacement and change the schedule" |
| [vacation-move.md](vacation-move.md) | "Move Serova's vacation from 30.11 to 03.11–10.11" |
| [admin-shifts-fill.md](admin-shifts-fill.md) | "Propose admin shift coverage because of Serova's vacation" |
| [calendar-delete-blocks.md](calendar-delete-blocks.md) | "Delete Kirill's / Lena's block…" |
| [shift-swap.md](shift-swap.md) | "Swap Olya and Katya tomorrow" |
| [schedule-replace-staff.md](schedule-replace-staff.md) | "Remove BMA, put NDV" / "Belova → Novikov" |

### More of the same shape (add a page when useful)

- "Put a block for Nina today from 21 to 22" — calendar create + confirm ([calendar.md](../calendar.md))
- "Take X off the shift + move the client booking" — multi-step plan ([assistant.md](../assistant.md))
- Finance / BI "where do I click" with allowed links only — live evals in `/assistant/eval`

## Template (new case)

```markdown
# Title

**Claim:** One marketing-ready sentence.

## Ask

> Exact staff message (English example; use fictional names).

## Done

What Max does (tools, pending confirm, outcome).

## Proof

- Runbooks / eval ids / group_ops notes
```

Writes never auto-apply in group chat: manager confirms. See [assistant-groups.md](../assistant-groups.md).

## How to publish (later)

On [freesaas.ru](https://freesaas.ru): CMS `/page/cases/` (or `linkRW=/cases/`), RU/EN. Lead with the **Ask** quote; omit internal UUIDs. CTA: demoru/demoen + how to connect Max ([assistant.md](../assistant.md), [connect-mcp.md](../connect-mcp.md) for external agents).
