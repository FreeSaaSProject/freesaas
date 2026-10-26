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
| `/banks/settings` | Superadmin: OAuth/token keys → `cfg/modules/banks.ini`; mTLS file upload |
| `/banks/settings/certs` | Superadmin: upload one mTLS file (cert/key/ca) |
| `/banks/oauth/{provider}/start` | OAuth/token start (`tochka`\|`alfa_*`\|`tbank_*`\|`sber_*`) |
| `/banks/oauth/{provider}/callback` | OAuth callback |
| `/banks/connections/{id}` | Remote accounts, link/unlink/create ledger, refresh |
| `/banks/connections/{id}/sync` | Manual sync (POST date_from/date_to) |
| `/banks/sync-account` | Sync by ledger `account_id` (from `/ledger/moves` toolbar) |
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
| `alfa_ul` | H2H ЮЛ: OAuth + optional mTLS. Sandbox first (`alfa_ul_sandbox=1`), then prod keys |
| `tbank_ul` | T-API accounts + bank-statement; token or T-ID OAuth |
| `tbank_fl` | T-ID debit accounts; statement needs partner scope |
| `sber_ul` | СберБизнес ID + `/v2/statement/transactions` (mTLS often required) |
| `sber_fl` | Open Banking PE; fill `sber_fl_*` bases after partner access |

### Alfa UL (H2H) — sandbox → prod

1. [developers.alfabank.ru](https://developers.alfabank.ru) — consent / contract; wait until Alfa enables API.
2. [selfservice/sandbox](https://developers.alfabank.ru/selfservice/sandbox/) — H2H integration: copy client_id/secret; set **Redirect URI** to  
   `https://YOUR_HOST/banks/oauth/alfa_ul/callback` (prod host, not `http://localhost`).
3. Scopes for ledger sync: **customer** + **transactions** (+ openid/profile/email). Skip b2b-sbp / c2b-sbp / payment unless you need them.
4. Install Минцифры CA on the host (OS trust store).
5. `/banks/settings`: enable **Альфа ЮЛ: песочница**, paste client_id/secret/scope/redirect, **upload** mTLS cert/key/ca (stored in `cfg/modules/banks-certs/`) → `/banks` → connect Alfa UL → link accounts → sync.
6. After sandbox passes, Alfa unlocks prod API Key / OAuth; turn off sandbox, put prod credentials, keep same redirect URI.

Sandbox hosts (forced when `alfa_ul_sandbox=1`): `id-sandbox.alfabank.ru`, `sandbox.alfabank.ru/oidc/token`, `sandbox.alfabank.ru`.

### Agent token (Surface A)

```bash
# Status / diagnose
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://HOST/agent/banks/status"

# Save settings (secrets omit to keep)
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Content-Type: application/json" \
  "https://HOST/agent/banks/settings" \
  -d '{"alfa_ul_sandbox":"1","alfa_ul_client_id":"…","alfa_ul_client_secret":"…","alfa_ul_scope":"openid customer transactions profile email"}'

# Upload mTLS (base64)
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Content-Type: application/json" \
  "https://HOST/agent/banks/certs" \
  -d "{\"provider\":\"alfa_ul\",\"kind\":\"cert\",\"filename\":\"sandbox_cert.cer\",\"content_base64\":\"$(base64 < sandbox_cert.cer | tr -d '\n')\"}"
# repeat kind=key (and ca if needed)
```

Session JSON twins: `GET|POST /banks/settings?format=json`, `POST /banks/settings/certs?format=json` (SUPERADMIN).

## Badges / ledger toolbar

EventBus: `ledger.accounts.badges`, `ledger.moves.badges`. Banks fills `by_id` HTML chips on linked accounts and bank-sourced moves (plus «unclassified» when type = default).

EventBus: `ledger.moves.list.toolbar` — when `/ledger/moves` has a selected account linked with sync enabled and the user has `banks.sync`, banks injects a date-range sync form that POSTs to `/banks/sync-account` and returns to the list (`ret`). Day-to-day sync lives on ДПС; `/banks` stays for OAuth and account linking.

## Auto sync (cron)

Platform job `banks.sync` every 30 minutes via `/cron/tick` (not soft-cron). Settings in `cfg/modules/banks.ini` / `/banks/settings`:

- `auto_sync_enabled` (default 1) — handler no-ops when 0
- `auto_sync_lookback_days` (default 2) — `date_from` window ending today

Sync uses **connection access tokens** in DB. Empty `*_client_id` in ini does not block sync while the token is fresh. When the token expires, refresh needs OAuth keys (`providerConfigured`) or a new connect. Status line on `/banks` shows last cron result.

Unclassified bank moves (in `base_bank_tx_map` with type = `default_move_type_id`): filter `/ledger/moves?bank_review=1` + home notice chip.

## Health

- `banks.default_move_type` — warns if `default_move_type_id` empty
- `banks.worker_token` — warns if jobs token missing / `CHANGE_ME`
- `banks.auto_sync` — warns if sync-enabled links exist but tokens expired and cannot refresh without OAuth keys
