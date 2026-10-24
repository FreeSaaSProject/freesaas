# Replace one person on the schedule (acronyms ok)

**Claim:** Staff use short labels (БМА, НДВ) or surnames; Max maps them to users and drafts remove + add on the target day.

## Ask

> @…_bot 25.10 убери из расписания БМА и поставь НДВ  

Follow-up shape:

> Белову убрать из графика и поставить Новикова  

## Done

1. Expand acronyms / surnames via staff dictionary on that day’s lanes  
2. Pending: delete old shift + create new (`workweeks.*`)  
3. One confirm — both steps (half-done after confirm was a bug we fixed: batch/plan must apply fully)  

Related: taking someone off a shift may also reassign open client bookings to another skilled staffer in the same plan.

## Proof

- Runbooks: [assistant-groups.md](../assistant-groups.md), [assistant.md](../assistant.md)  
- Maintainers: [Assistant groups — acronym swap](2f2bb67e-708e-4b9f-8931-21e1c97762a4)
