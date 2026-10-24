# Delete calendar blocks by person and weekday

**Claim:** Floor managers remove «blocks» (calendar events) in natural language; Max finds event ids for that staffer and queues deletes.

## Ask

> @…_bot удали блок у Кирилла в среду и четверг  

> @…_bot удали блок у Лены в субботу и у Кирилла в воскресенье  

Same family: «Макс удали блок у Нины с 21».

## Done

1. Resolve person on the relevant day(s) via `calendar.day` (compact events keep `id` + time even when truncated)  
2. Match blocks by staff + weekday/time  
3. Pending `calendar.events.delete` (confirm needs `calendar.delete` + role flag `EVT_DELETE`)  

Without event ids in the day payload, Max used to ask for a manual id — that path was fixed for group use.

## Proof

- Runbooks: [calendar.md](../calendar.md), [assistant-groups.md](../assistant-groups.md)  
- Maintainers: [Assistant groups — delete block](2f2bb67e-708e-4b9f-8931-21e1c97762a4)
