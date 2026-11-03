# Warm standby (local PC)

Goal: if shared hosting dies for days, staff keep working on a LAN PC with near-hourly data. Public storefront / bots webhooks / OAuth may stay down.

Requires module `backups` ≥ 0.3 with CDC triggers on the **primary**.

## Topology

1. **Primary**: normal FreeSaaS; `cdc_enabled=1`; nightly `.light.tar[.enc]` (db+cfg), weekly hard full, hourly `.incr.tar[.enc]` → FTP.
2. **Standby PC**: same code; `cfg/config.ini` → `app.standby=passive`, distinct `session_name`, LAN `app.base_url`, same FTP + passphrase as primary. Cron `/cron/tick` or `php bin/freesaas.php cron tick`.
3. Every ~15 min on PC: `php bin/freesaas.php backups standby-sync --format=json` (or host cron).

## First bring-up on PC

```bash
# Empty MySQL + config.ini [connect], then:
php bin/freesaas.php bootstrap-from-backup --remote-path=INSTANCE/latest.tar.enc --passphrase='…'
# set app.standby=passive and standby_probe_url=https://primary/health
php bin/freesaas.php backups standby-sync --format=json
```

## Modes (`app.standby`)

| Value | Meaning |
|-------|---------|
| _(empty)_ | Primary |
| `passive` | Sync + health probe only; product cron muted |
| `active` | Promote: terminals/1C cron allowed; backups may run for demote packs; messengers still muted unless `standby_allow_bots=1` |

## Promote (hosting down)

1. On PC: `app.standby=active` (restart php/apache if needed).
2. Point LAN devices (Termy, terminals, 1C clients) at PC IP/hostname (`/t`, `/api_termy.php`, `/api.php`, `/api1c.php`).
3. Staff open admin on LAN URL.
4. Optional: enable `standby_allow_bots=1` if you need outbound staff alerts from PC.

## Demote (hosting back)

1. On PC ensure a fresh full pack uploaded (active + backups enabled) **or** copy pack off PC.
2. On primary: empty DB → `bootstrap-from-backup` from that pack.
3. PC → `app.standby=passive` again; re-point LAN devices to primary when ready.
4. Never write to both DBs at once.

## Health probe

With `standby=passive` and `standby_probe_url=https://primary/health`, cron `backups.standby_health` every 5 min notifies via `bots.send` when primary flips from OK to fail (needs bots module + notify settings on standby).

## Agent / CLI

```bash
php bin/freesaas.php backups standby-status --format=json
php bin/freesaas.php backups standby-sync --format=json
curl -H "X-FreeSaaS-Agent-Token: $TOKEN" https://standby/agent/backups/standby
curl -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" https://standby/agent/backups/standby/sync
```

See also [backups.md](backups.md).
