# Swap two people on tomorrow’s shifts

**Claim:** «Поменяй Олю и Катю» works from the day roster — Max must call tools first, not ask for IDs when the day has a unique match.

## Ask

> Поменяй завтра Олю и Катю местами сменами — одну в первую, другую во вторую.

## Done

1. `CALL workweeks.day` (or list) for tomorrow  
2. Resolve Оля / Катя on that roster (if two Кати — ask surname **after** search)  
3. Pending writes to swap lanes / shift slots  
4. Confirm with `workweeks.edit`  

Locked in as live evals so regressions («asked for surname without opening the day») fail CI-style `/assistant/eval`.

## Proof

- Eval: `live.workweeks_resolve_names`, `live.workweeks_two_tanyas`  
- Fixtures: `workweeks_resolve_names.json`, `workweeks_resolve_names_two_tanyas.json`  
- Runbook: [assistant.md](../assistant.md)
