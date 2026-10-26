# Banks (Tochka / Alfa / T-Bank / Sber → ledger)

Product module `banks`: OAuth (and token) connections to Tochka, Alfa, T-Bank, Sber — each as business (UL) and personal (FL) where the bank exposes it. Link remote accounts to `base_accounts`, sync statements into `base_account_moves`. Soft-dep on `llm` for type classification; otherwise `banks.default_move_type_id`.

## Enable

```bash
php bin/freesaas.php module enable banks --format=json
cp modules/banks/config.example.ini cfg/modules/banks.ini
# default_move_type_id + worker_token on /banks hub; per-bank keys via setup wizard
```

## Human UI

| Path | Role |
|------|------|
| `/banks` | **Hub**: sync defaults + Alfa sandbox/prod integrations + connections |
| `/banks/integrations/{id}` | Superadmin: one Alfa env (keys, mTLS, Connect, scope probe) |
| `/banks/setup/{provider}` | Superadmin **setup wizard** (first-time steps) |
| `/banks/settings?tab=` | Superadmin **flat settings** (view/edit keys without wizard). Hub shows «Settings» when the bank is already configured |
| `/banks/settings/certs` | Upload one mTLS file (cert/key/ca) |
| `/banks/settings/csr` | Generate/download Alfa UL prod CSR |
| `/banks/settings/probe` | Probe Alfa UL client_id/secret (blocked on private/loopback egress) |
| `/banks/oauth/{provider}/start` | OAuth/token start |
| `/banks/oauth/{provider}/callback` | OAuth callback |
| `/banks/connections/{id}` | Remote accounts, link/unlink/create ledger, refresh, **delete** (keeps certs + ledger accounts) |

### Connections vs “primary”

There is **no primary connection**. Each row in `/banks` is independent:

- **Manual sync** on `/banks/connections/{id}` → only that connection’s linked accounts.
- **Cron / sync-account** → every active connection that has ≥1 ledger link with `SYNC_ENABLED`.
- Hub card **Open** → most recently updated connection for that provider (convenience only).

Deleting a connection removes its `base_bank_connections` row, its `base_bank_account_links`, and related `base_bank_tx_map` rows. It does **not** delete `cfg/modules/banks-certs/` or ledger accounts.
| `/banks/connections/{id}/sync` | Manual sync |
| `/banks/sync-account` | Sync by ledger `account_id` |
| `/banks/jobs/sync` | Cron; header `X-Banks-Token` |

**Egress IP:** Connect, OAuth start, and probe refuse loopback/private IPs (`BanksEgressIp`). Whitelist the public IP shown in the wizard **Server IP** step (and on status).

## Setup wizard (agents)

Same steps as the human UI. Prefer Surface A:

```bash
# Overview + per-provider status
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://HOST/agent/banks/status"
# → setup.{provider}.{status,current_step,configured,connected}, setup_ui, egress_ip

# Full steps for one bank (ask_user / agent_can / browser)
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://HOST/agent/banks/setup?provider=alfa_ul"
```

Response highlights:

| Field | Meaning |
|-------|---------|
| `ask_user_now` | Exact text to ask the human if you cannot complete the step yourself |
| `agent_can_now` | Capabilities you may perform without the human (`save_settings`, `upload_cert`, `probe`, …) |
| `browser_now` | Portal URLs — open if you have a browser |
| `agent_can_ops` | Map capability → HTTP op (`POST /agent/banks/certs`, …) |
| `setup.steps[]` | Full list with `ask_user`, `agent_can`, `browser`, `complete` |

Advance progress:

```bash
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Content-Type: application/json" \
  "https://HOST/agent/banks/setup" \
  -d '{"provider":"alfa_ul","action":"mark_done","step_id":"egress"}'
# action: mark_done | skip | save | csr_generate
```

Session twin: `GET|POST /banks/setup/{provider}?format=json` (SUPERADMIN).

**Policy for the agent:** read `ask_user_now` every turn. If you have files/keys → call `agent_can_ops`. If you have a browser and `browser_now` is set → open those URLs. Otherwise paste `ask_user_now` to the human. Re-GET until `setup.status` is `ready` or `connected`.

## Agent JSON (session)

Session auth; `?format=json` or `Accept: application/json`. Ops: `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/banks?format=json'
curl -sS -b jar.txt 'https://example/banks/setup/alfa_ul?format=json'
curl -sS -b jar.txt 'https://example/banks/connections/1?format=json'
```

OAuth start with JSON returns `{authorize_url}` (no redirect). Shortcuts without browser OAuth:

- Tochka sandbox (`tochka_sandbox=1`)
- T-Bank Business self-service token (`tbank_ul_access_token` in ini)
- Alfa UL ApiKey (H2H card; IP allowlist = egress)

| Provider | Notes |
|----------|--------|
| `alfa_ul` | Full wizard: sandbox TLS → creds → connect → prod H2H → CSR → prod cert → connect |
| `tochka` | Short: egress → sandbox/OAuth → connect |
| `tbank_ul` / `tbank_fl` / `sber_ul` / `sber_fl` | In hub as **not verified** (code present; not proven on prod). `alfa_fl` removed — bank does not grant FL PP scopes yet |

### Alfa UL (H2H) — sandbox → prod

Follow `/banks/setup/alfa_ul` (or agent setup above). Summary:

1. Whitelist egress IP.
2. Sandbox TLS from welcome email `test_cert.zip` (not cabinet «issue TLS»).
3. H2H sandbox card: client_id/secret and/or ApiKey → probe → Connect.
4. Prod: Create H2H integration; redirect URI from wizard; generate CSR in FreeSaaS; attach on portal; upload issued `.cer`; uncheck sandbox; paste prod keys → Connect.

CSR: `POST /banks/settings/csr` or `POST /agent/banks/csr` / setup `action=csr_generate`. Download: session `GET /banks/settings/csr?action=download`.

Sandbox hosts (`alfa_ul_sandbox=1`): `id-sandbox.alfabank.ru`, `sandbox.alfabank.ru`. Prod: `id.alfabank.ru`, `baas.alfabank.ru`.

### Agent token (Surface A) — configure without UI

```bash
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://HOST/agent/banks/status"

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Content-Type: application/json" \
  "https://HOST/agent/banks/settings" \
  -d '{"alfa_ul_sandbox":"1","alfa_ul_client_id":"…","alfa_ul_client_secret":"…","alfa_ul_scope":"openid customer transactions profile email"}'

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Content-Type: application/json" \
  "https://HOST/agent/banks/certs" \
  -d "{\"provider\":\"alfa_ul\",\"kind\":\"cert\",\"filename\":\"sandbox_cert.cer\",\"content_base64\":\"$(base64 < sandbox_cert.cer | tr -d '\n')\"}"
```

## Badges / ledger toolbar

EventBus: `ledger.accounts.badges`, `ledger.moves.badges`, `ledger.moves.list.toolbar`, `core.notices.collect` (unclassified bank moves).

## Cron

`banks.sync` every 30 min via platform `/cron/tick` when `auto_sync_enabled=1` on the hub. Lookback days = `auto_sync_lookback_days`.
