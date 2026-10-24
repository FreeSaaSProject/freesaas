# Move a vacation and clear shifts

**Claim:** One chat message reschedules leave and removes that person’s shifts in the new period — confirmed as one multi-step plan.

## Ask

> @…_bot перенеси отпуск Серовой с 30.11 на 03.11-10.11

## Done

1. Find staff by surname → open vacation record  
2. Draft update of vacation dates (partial-safe update; flags/description kept)  
3. List `workweeks` in the new period → draft deletes for those shifts  
4. One confirm button applies the whole plan  

Local harness checks used the same shape («Перенеси отпуск Паниной…»): vacation dates moved, shifts in-range removed, later shifts untouched.

## Proof

- Runbooks: [assistant-groups.md](../assistant-groups.md), [vacations.md](../vacations.md), [assistant.md](../assistant.md) (plans / pending batch)  
- Maintainers: [Assistant groups — vacation move](2f2bb67e-708e-4b9f-8931-21e1c97762a4)
