# Client bonuses

Product module `bonuses`: client loyalty ledger (`base_bonuspay`) and event settlement (oper `bonusCount` / `bonusAnal`).

## Enable

```bash
php bin/freesaas.php module enable bonuses --format=json
```

Or `[modules] bonuses=1` in `cfg/config.ini`. Role flag `EVT_BONUS` shows bonus fields on calendar events.

## Human UI

- `/bonuses` — ledger list / create / edit
- `/bonuses/clients/{id}` — balance + history
- Calendar event edit — bonus section (use / discount / apply) when module on + `EVT_BONUS`

Apply is explicit (save event, then Apply). Does not auto-run on every save.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/bonuses?format=json'
curl -sS -b jar.txt 'https://example/bonuses/clients/123?format=json'
curl -sS -b jar.txt 'https://example/bonuses/events/456/quote?format=json'
curl -sS -b jar.txt -X POST 'https://example/bonuses/events/456/apply?format=json'
```

## Notes

- Does not port full miniPrice/dynPrice; uses event `CONTENT_PRICE` + procedure type % + client status `bonus`.
- Promoter audio `BONUS TIME` stays in `likespa`.
