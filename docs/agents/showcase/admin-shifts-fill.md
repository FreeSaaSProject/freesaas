# Fill administrator shifts around a vacation

**Claim:** Max can propose how to cover admin lanes (1st and 2nd shifts) when someone is on leave — then draft the roster changes.

## Ask

> @…_bot Макс, а можешь предложить распределение смен чтобы заполнить администраторов (нужны 1 и 2 смены) из-за отпуска Серовой?

## Done

1. Read vacation window + current admin roster (`workweeks.day` / list over the range)  
2. Propose who can take lane I / II on each day (names from enabled staff, not invented)  
3. After staff agree in chat, queue `workweeks` creates/updates as pending  
4. Manager confirms  

This is the planning half of [vacation-move.md](vacation-move.md): move leave first, then fill holes.

## Proof

- Runbooks: [assistant-groups.md](../assistant-groups.md), workweeks agent ops  
- Maintainers: [Assistant groups](2f2bb67e-708e-4b9f-8931-21e1c97762a4)
