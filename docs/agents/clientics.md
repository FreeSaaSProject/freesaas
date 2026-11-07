# Clientics / Клиентикс (dual-run)

Product module `clientics`: dual-run bridge with Clientics into FreeSaaS.

- **Pull:** clients → `base_clients`; appointments → `base_events` (calendar); users/services id maps; optional WaitingList → CRM lead pipeline when API inventory finds it.
- **Push:** outbox for clients (when `push_enabled`); appointments only when `appointment_sot=freesaas` and `push_enabled`.
- **Medcards:** skipped until API inventory reports MedicalCards (not in public Restapi today).
- Echo control: `ClienticsSyncContext` / `sync_origin=clientics`.

## Appointment SoT

| `appointment_sot` | New visits | Mirror |
|-------------------|------------|--------|
| `clientics` (default) | Create in Clientics | FreeSaaS pull + webhook; local calendar creates are **not** pushed |
| `freesaas` | Create in FreeSaaS calendar | Outbox → Clientics `Appointments` add/edit |

Never run both UIs as writers without flipping SoT — that is how double-book is avoided.

## Enable

```bash
cp modules/clientics/config.example.ini cfg/modules/clientics.ini
php bin/freesaas.php module enable clientics --format=json
php bin/freesaas.php migrate run --module=clientics --format=json
```

Ini: `domain`, `account_id`, `user_id`, `access_token`, `worker_token`, `webhook_token`, `pull_enabled`, `push_enabled`, `appointment_sot`, `default_operator_id`.

Soft deps: `calendar` (appointments), `crm` (WaitingList → leads), `catalog`/`procedures` (service name maps).

## Cutover runbook

1. `appointment_sot=clientics`, `pull_enabled=1`, soak client + appointment pulls; map users (`action=users`).
2. `POST /clientics` `action=inventory` — confirm modules; medcards stay skipped if absent; WaitingList import only if flagged.
3. Enable `push_enabled=1` for **clients** first; verify id map / outbox.
4. Flip `appointment_sot=freesaas`; create new visits only in FreeSaaS; flush outbox.
5. Operators leave Clientics UI → set `pull_enabled=0` / disable module when ready.

## Agent JSON (session)

```bash
curl -sS -b jar.txt 'https://example/clientics?format=json'

curl -sS -b jar.txt -X POST 'https://example/clientics/settings?format=json' \
  -d 'pull_enabled=1&push_enabled=0&appointment_sot=clientics&account_id=…&user_id=…&access_token=…&worker_token=…&webhook_token=…'

curl -sS -b jar.txt -X POST 'https://example/clientics?format=json' -d 'action=ping'
curl -sS -b jar.txt -X POST 'https://example/clientics?format=json' -d 'action=inventory'

curl -sS -b jar.txt -X POST 'https://example/clientics/import?format=json' \
  -d 'action=clients&dry_run=1&from=2026-01-01&to=2026-01-07'
curl -sS -b jar.txt -X POST 'https://example/clientics/import?format=json' \
  -d 'action=appointments&dry_run=1&from=2026-01-01&to=2026-01-07'
curl -sS -b jar.txt -X POST 'https://example/clientics/import?format=json' -d 'action=users&dry_run=1'
curl -sS -b jar.txt -X POST 'https://example/clientics/import?format=json' -d 'action=outbox'
```

Webhook (Clientics eventHandlers → our URL):

`POST /clientics/webhook?token=WEBHOOK_TOKEN` (or header `X-Clientics-Webhook-Token`).

Cron jobs (`X-Clientics-Token`, `assistant: false`): `/clientics/jobs/ping`, `import-clients`, `import-appointments`, `import-refs`, `outbox`, `inventory`.

## Notes

- Client list / appointments APIs are day-scoped — range imports need `from`/`to` (max 400 days).
- Map Clientics executors → staff before appointment pull (`default_operator_id` is a fallback).
- Secrets masked in GET responses.
- Tables: `base_clientics_id_map`, `base_clientics_sync_state`, `base_clientics_outbox`, `base_clientics_webhook_events` (uninstall leaves tables).
