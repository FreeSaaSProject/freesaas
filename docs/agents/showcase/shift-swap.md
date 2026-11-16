# Swap two people on tomorrow's shifts

**Claim:** "Swap Olya and Katya" works from the day roster — Max must call tools first, not ask for IDs when the day has a unique match.

## Ask

> Swap Olya and Katya on tomorrow's shifts — one on the first, the other on the second.

## Done

1. `CALL workweeks.day` (or list) for tomorrow  
2. Resolve Olya / Katya on that roster (if two Katyas — ask surname **after** search)  
3. Pending writes to swap lanes / shift slots  
4. Confirm with `workweeks.edit`  

Locked in as live evals so regressions ("asked for surname without opening the day") fail CI-style `/assistant/eval`.

## Proof

- Eval: `live.workweeks_resolve_names`, `live.workweeks_two_tanyas`  
- Fixtures: `workweeks_resolve_names.json`, `workweeks_resolve_names_two_tanyas.json`  
- Runbook: [assistant.md](../assistant.md)
