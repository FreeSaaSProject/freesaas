# Client bonuses

Product module `bonuses`: client loyalty ledger (`base_bonus_moves`) and event settlement (quote / apply).

## Enable

```bash
php bin/freesaas.php module enable bonuses --format=json
```

Or `[modules] bonuses=1` in `cfg/config.ini`. Role flag `EVT_BONUS` shows bonus fields on calendar events.

## Schema

Table `base_bonus_moves` (lowercase FreeSaaS columns): `client_id`, `direction`, `amount`, `kind` (`manual` | `event_accrue` | `event_spend`), `ref_type` / `ref_id`, `created_at`, `expires_at`, `note`, `dismissed`.

Legacy `base_bonuspay` is dropped by migration `001_create_bonus_moves` — no oper compatibility.

## Human UI

- `/bonuses` — ledger list / create / edit
- `/bonuses/clients/{id}` — balance + history
- Calendar event edit — bonus section (use / discount / apply) when module on + `EVT_BONUS`

Apply is explicit (save event, then Apply). Does not auto-run on every save.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/bonuses?format=json&client_id=123'
curl -sS -b jar.txt 'https://example/bonuses/clients/123?format=json'
curl -sS -b jar.txt 'https://example/bonuses/events/456/quote?format=json'
curl -sS -b jar.txt -X POST 'https://example/bonuses/events/456/apply?format=json'
```

## Notes

- Uses event `CONTENT_PRICE` + procedure type % + client status `bonus`.
- Promoter audio `BONUS TIME` stays in `likespa`.
