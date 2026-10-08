# Banks (Tochka / Alfa / T-Bank / Sber → ledger)

Product module `banks`: OAuth (and token) connections to Tochka, Alfa, T-Bank, Sber — each as business (ЮЛ) and personal (ФЛ) where the bank exposes it. Link remote accounts to `base_accounts`, sync statements into `base_account_moves`. Soft-dep on `llm` for type classification; otherwise `banks.default_move_type_id`.

## Enable

```bash
php bin/freesaas.php module enable banks --format=json
cp modules/banks/config.example.ini cfg/modules/banks.ini
# set client_id/secret/redirect_uri per provider; default_move_type_id; worker_token
```

## Human UI

| Path | Role |
|------|------|
| `/banks` | Connections list + connect / configure buttons |
| `/banks/settings` | Superadmin: OAuth/token keys → `cfg/modules/banks.ini` |
| `/banks/oauth/{provider}/start` | OAuth/token start (`tochka`\|`alfa_*`\|`tbank_*`\|`sber_*`) |
| `/banks/oauth/{provider}/callback` | OAuth callback |
| `/banks/connections/{id}` | Remote accounts, link/unlink/create ledger, refresh |
| `/banks/connections/{id}/sync` | Manual sync (POST date_from/date_to) |
| `/banks/jobs/sync` | Cron; header `X-Banks-Token` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. Ops: `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/banks?format=json'
curl -sS -b jar.txt 'https://example/banks/connections/1?format=json'
curl -sS -b jar.txt -X POST 'https://example/banks/connections/1?format=json' \
  -d 'action=link&external_account_id=…&account_id=20'
curl -sS -b jar.txt -X POST 'https://example/banks/connections/1/sync?format=json' \
  -d 'date_from=2026-10-01&date_to=2026-10-08'
curl -sS -X POST 'https://example/banks/jobs/sync' \
  -H 'X-Banks-Token: …' -d 'date_from=2026-10-06&date_to=2026-10-08'
```

OAuth start with JSON returns `{authorize_url}` (no redirect). Shortcuts without browser OAuth:

- Tochka sandbox (`tochka_sandbox=1`)
- T-Bank Business self-service token (`tbank_ul_access_token` in ini)

| Provider | Notes |
|----------|--------|
| `tbank_ul` | T-API accounts + bank-statement; token or T-ID OAuth |
| `tbank_fl` | T-ID debit accounts; statement needs partner scope |
| `sber_ul` | СберБизнес ID + `/v2/statement/transactions` (mTLS often required) |
| `sber_fl` | Open Banking PE; fill `sber_fl_*` bases after partner access |

## Badges

EventBus: `ledger.accounts.badges`, `ledger.moves.badges`. Banks fills `by_id` HTML chips on linked accounts and bank-sourced moves (plus «unclassified» when type = default).

## Health

- `banks.default_move_type` — warns if `default_move_type_id` empty
- `banks.worker_token` — warns if jobs token missing / `CHANGE_ME`
