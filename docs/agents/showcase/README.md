# Showcase — real asks to Max (in-system assistant)

Staff write a sentence in chat (UI `/assistant`, personal bot, or Max **group**). Max reads product data via `agent.ops`, queues **writes as pending drafts**, and a person with rights confirms. These pages are **examples of day-to-day work**, not CLI install benchmarks.

**Names, stocks, suppliers, and products below are fictional** (anonymized for public docs). Shape of the asks matches real production use.

| Collection | Purpose |
|------------|---------|
| **This folder** (`showcase/`) | Natural-language ops Max already handles (or is wired to handle) |
| [`../cases/`](../cases/) | Reproducible platform benchmarks (`bootstrap` / `module enable` / `doctor`) |

Language in-repo is English; **Ask** quotes keep Russian staff wording (what goes on the marketing site later).

## Cases

| Case | Staff ask (short) |
|------|-------------------|
| [receipt-inbound.md](receipt-inbound.md) | «Заприходуй на склад Северный… МаркетПлюс» |
| [sick-cover.md](sick-cover.md) | «Орлова заболела… найди замену и поменяй расписание» |
| [vacation-move.md](vacation-move.md) | «Перенеси отпуск Серовой с 30.11 на 03.11–10.11» |
| [admin-shifts-fill.md](admin-shifts-fill.md) | «Предложи распределение смен админов из‑за отпуска Серовой» |
| [calendar-delete-blocks.md](calendar-delete-blocks.md) | «Удали блок у Кирилла / у Лены…» |
| [shift-swap.md](shift-swap.md) | «Поменяй завтра Олю и Катю местами» |
| [schedule-replace-staff.md](schedule-replace-staff.md) | «Убери БМА, поставь НДВ» / «Белову → Новикова» |

### More of the same shape (add a page when useful)

- «Поставь блок Нине сегодня с 21 до 22» — calendar create + confirm ([calendar.md](../calendar.md))
- «Сними X со смены + перенеси запись клиента» — multi-step plan ([assistant.md](../assistant.md))
- Finance / BI «куда кликнуть» with allowed links only — live evals in `/assistant/eval`

## Template (new case)

```markdown
# Title

**Claim:** One marketing-ready sentence.

## Ask

> Exact staff message (RU quote ok; use fictional names).

## Done

What Max does (tools, pending confirm, outcome).

## Proof

- Runbooks / eval ids / group_ops notes
```

Writes never auto-apply in group chat: manager confirms. See [assistant-groups.md](../assistant-groups.md).

## How to publish (later)

On [freesaas.ru](https://freesaas.ru): CMS `/page/cases/` (or `linkRW=/cases/`), RU/EN. Lead with the **Ask** quote; omit internal UUIDs. CTA: demoru/demoen + how to connect Max ([assistant.md](../assistant.md), [connect-mcp.md](../connect-mcp.md) for external agents).
