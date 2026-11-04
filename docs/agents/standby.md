# Warm standby (local PC)

Goal: if shared hosting dies for days, staff keep working on a LAN PC with near-hourly data. Public storefront / bots webhooks / OAuth may stay down.

Requires module `backups` ≥ 0.3 with CDC triggers on the **primary**.

**Preferred PC agent:** workers plugin `freesaas` (dashboard + autostart) — see [workers.md](workers.md) § plugin `freesaas`. It only orchestrates PHP CLI (`standby-sync`, `doctor`, `cron tick`, `update`); CDC/restore SoT stays in `modules/backups`.

## Topology

1. **Primary**: normal FreeSaaS; `cdc_enabled=1`; nightly `.light.tar[.enc]` (db+cfg), weekly hard full, hourly CDC packs (default `.diff.sNNN.tar[.enc]`, or chain `.incr.sNNN.tar[.enc]`) → FTP.
2. **Standby PC**: same code; `cfg/config.ini` → `app.standby=passive`, distinct `session_name`, LAN `app.base_url`, same FTP + passphrase as primary. Set `cfg/modules/backups.ini` → `instance=` to the **primary** FTP folder name (same as on primary; often the primary hostname). Empty falls back to this host’s `base_url` and lists the wrong remote dir. Passphrase: ini `passphrase=` or file `cfg/passphrase.txt` (read by `BackupConfig::passphrase()`). Keep `cdc_enabled=0` on standby. Cron `/cron/tick` or `php bin/freesaas.php cron tick` (or workers plugin `freesaas`).
3. Sync every ~15 min: cron job `backups.standby_sync` (allowed on passive), and/or workers plugin loop, and/or `php bin/freesaas.php backups standby-sync --format=json`. Differential: applies latest `.diff` after baseline. Chain: applies `sNNN` in order and errors on gaps. After a manual `bootstrap-from-backup`, mark that pack as baseline (`standby_applied_full=…`, cursor/seq `0`) before the first sync so it does not try to redeploy full into a non-empty DB.

## First bring-up on PC

```bash
# Empty MySQL + config.ini [connect], then:
php bin/freesaas.php bootstrap-from-backup --remote-path=INSTANCE/latest.light.tar.enc --passphrase='…'
# or put secret in cfg/passphrase.txt and omit --passphrase
# set app.standby=passive and standby_probe_url=https://primary/health
# backups.ini: instance=INSTANCE, encrypt=1 (or passphrase.txt)
php bin/freesaas.php backups standby-sync --format=json

# Optional UX: same clone, enable workers plugin freesaas (+ audioloader if badges)
cp workers/host.example.json workers/host.local.json
# enabled_plugins: [..., "freesaas"]
cp workers/plugins/freesaas/configs/example.json workers/plugins/freesaas/configs/local.json
# edit local_base_url / php_bin; freesaas_root empty = this repo
./workers/bin/freesaas-workers install          # Windows: workers\bin\freesaas-workers.cmd …
./workers/bin/freesaas-workers start freesaas
./workers/bin/freesaas-workers autostart on    # macOS LaunchAgent / Windows Task Scheduler FreeSaaSWorkers
# Dashboard: only http://127.0.0.1:8766/ in a browser on this PC (not via Tailscale IP)
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

## Cron on standby

| Job | Every | Mode |
|-----|-------|------|
| `backups.standby_health` | 5 min | always on standby |
| `backups.standby_sync` | 15 min | always on standby (pull FTP packs) |

Both are allowlisted in `StandbyMode` so passive hosts still sync without enabling product cron.

## Agent / CLI

```bash
php bin/freesaas.php backups standby-status --format=json
php bin/freesaas.php backups standby-sync --format=json
curl -H "X-FreeSaaS-Agent-Token: $TOKEN" https://standby/agent/backups/standby
curl -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" https://standby/agent/backups/standby/sync
```

See also [backups.md](backups.md), [workers.md](workers.md).
