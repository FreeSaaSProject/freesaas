# Sick day — find cover and change the roster

**Claim:** When someone calls in sick, staff ask Max to find who can cover tomorrow and to draft the schedule change for confirmation.

## Ask

> Marina Orlova is sick and will not come in tomorrow. Find a replacement for tomorrow, get agreement, and change the schedule.

## Done

Typical flow (group or personal Max):

1. Resolve "Orlova" on tomorrow's roster via `workweeks.day` / staff search  
2. Propose a replacement from people who can take that lane/role  
3. Queue pending deletes/creates on `workweeks.*` (and calendar blocks if needed)  
4. Manager with `workweeks.edit` confirms; optional "get agreement" stays a human step in the chat  

Ambiguous first names (two "Katyas" on the day) → Max should list the day first, then ask surname — not guess.

## Proof

- Runbooks: [assistant.md](../assistant.md), [assistant-groups.md](../assistant-groups.md), workweeks / calendar docs  
- Related live evals: `live.workweeks_resolve_names`, `live.workweeks_two_tanyas` (name resolution on day roster)  
- Maintainers: [Assistant groups](2f2bb67e-708e-4b9f-8931-21e1c97762a4)
