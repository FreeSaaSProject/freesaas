# Backups (`backups`)

Alpha module: scheduled DB + file sets packed into **one** `.tar` / `.tar.enc`, uploaded to FTP/FTPS with retention, optional OpenSSL encryption, empty-DB deploy, **CDC-lite hourly increments**, and warm-standby helpers ([standby.md](standby.md)).

**Settings:** `/backups/settings` (SUPERADMIN). Live config: `cfg/modules/backups.ini` (see `modules/backups/config.example.ini`).

**Runs:** `/backups`. Cron job `backups.tick` every 1 minute advances an in-progress run (~40s budget) or starts one when `enabled=1` and local time ≥ `daily_at` with no run started after that stamp.

**Host check (permanent):** button on settings → `/backups/check`. Verifies PHP extensions/limits, disk, MySQL grants + **live CREATE TRIGGER probe**, PK coverage, CDC trigger coverage (warn until CDC installed), FTP write probe, encryption, last success age, cron freshness. Safe to re-run; probe table is dropped.

```bash
php bin/freesaas.php backups check --format=json
# HTTP twin (agent_token):
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://host/agent/backups/check"
# Session JSON:
curl -sS -b jar.txt 'https://host/backups/check?format=json'
```

**Agent ops (module session):** `GET /backups/check`, `GET /backups`, `POST /backups/run`, `GET /backups/remote`, `POST /backups/notify/test`, `GET|POST /backups/settings`, `POST /backups/restore`.

**Platform:** `GET /agent/backups/check` (agent_token). Empty-DB deploy: `POST /agent/bootstrap-from-backup` (agent_token or `var/setup.key`) and CLI `php bin/freesaas.php bootstrap-from-backup …`.

## Remote layout (single pack)

Hard full: `{ftp_base_path}/{instance}/{YYYY-MM-DD_HH-MM-SS}.tar` (or `.tar.enc`)  
Light: `{ftp_base_path}/{instance}/{YYYY-MM-DD_HH-MM-SS}.light.tar` (or `.tar.enc`)  
CDC differential (default): `{stamp}.diff.sNNN.tar` (or `.tar.enc`) — `s001`, `s002`, … since last light/full  
CDC chain (optional): `{stamp}.incr.sNNN.tar` — same numbering; **every** `s001…sNNN` required

### Schedule (defaults)

| Kind | When | Contents |
|------|------|----------|
| **light** | Every night after `daily_at` (`light_nightly=1`, default on) | `db.sql.gz` + `cfg.tar` only |
| **full** (hard) | Only if `hard_enabled=1`, every `full_every_days` (default 7) | db + cfg + checked file sources (video/audio off by default) |
| **CDC** | Every `incr_every_hours` when CDC on | Changed rows (`incr.sql.gz`) |

Hard is **off by default**. If hard runs the same night, light is skipped.

Hard full pack contents:

- `db.sql.gz`
- `cfg.tar`
- `files-<source>.tar` (optional sources)
- `manifest.json` (`format: 1`, `kind: full`)

Light pack: same without `files-*` (`format: 3`, `kind: light`).

CDC pack contents:

- `incr.sql.gz` (REPLACE/DELETE for changed PKs)
- `manifest.json` (`format: 2`, `kind: incr`, `incr_mode`, `incr_seq`, `base_full`, `cursor_from`/`cursor_to`)

### CDC modes (`incr_mode`)

| Mode | File tag | What each pack holds | Restore / standby |
|------|----------|----------------------|-------------------|
| **differential** (default) | `.diff.sNNN` | All journal changes **since last light/full** | Baseline + **latest** `.diff.sNNN` |
| **chain** | `.incr.sNNN` | Only the slice since previous pack | Baseline + **unbroken** `s001…sNNN` (gap = fail) |

Seq resets to `s001` after each successful light/full. Listing FTP shows missing numbers immediately (no time guessing).

## CDC hourly packs

1. Run host check (`/backups/check`) — need `mysql.trigger_probe` ok.
2. Migrate → Settings → **Install CDC triggers** (`cdc_enabled=1`, `incr_every_hours=1`, `incr_mode=differential`).
3. At least one light or hard pack once (baseline).
4. Cron starts a CDC pack when journal has rows past `incr_cursor`.

```bash
php bin/freesaas.php backups cdc-install --format=json
php bin/freesaas.php backups cdc-status --format=json
curl -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" https://host/agent/backups/cdc/install
```

Exclude list: built-in (`base_fullstats`, caches, cron, backup_*…) + `cdc_exclude` in ini.

Recommended FTP base: `…/freesaas-backups` (button **Copy FTP from audio** sets host/user/pass from audio storage and creates this folder + instance on the server).

## Encryption

Whole pack encrypted with `openssl enc -aes-256-cbc -pbkdf2 -iter 100000` (`Salted__`). Keep the passphrase off-server.

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 \
  -in 2026-01-01_03-30-00.tar.enc -out 2026-01-01_03-30-00.tar
tar xf 2026-01-01_03-30-00.tar
```

## Empty-DB deploy (recommended for new host)

Gate: **every table has zero rows** (or no tables). If any data exists → `409` / UI locked. Creating backups on a live DB is unaffected.

1. Deploy FreeSaaS code matching `manifest.json` versions; write `cfg/config.ini` `[connect]` to an **empty** database + `agent_token` or `var/setup.key`.
2. Feed the pack:

```bash
php bin/freesaas.php bootstrap-from-backup \
  --url=https://example.com/path/stamp.tar.enc \
  --passphrase='…' \
  --format=json

# or local file / FTP path configured in backups.ini:
# --file=/var/backups/incoming/stamp.tar.enc
# --remote-path=instance/stamp.tar.enc
```

HTTP twin:

```bash
curl -sS -X POST "https://new-host/agent/bootstrap-from-backup" \
  -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://…/stamp.tar.enc","passphrase":"…"}'
```

UI (module enabled, director): `/backups/restore` — same gate.

Steps: download → decrypt if needed → unpack → `DbJobs` restore → files + cfg merge (keeps local `[connect]`, `base_url`, `session_*`, tokens) → `migrate run`.

Then: `php bin/smoke.php`; log in with a user from the dump (no new admin from virgin bootstrap).

## Manual restore without FreeSaaS UI

Same as before: decrypt → `tar xf` → `gunzip -c db.sql.gz | mysql …` → copy files → edit `cfg` (`salts.site` never rotate) → migrate + smoke.

## Notifications

Settings: UI chips + optional `bots.send` for fail / success / stale.

## Limits

- Shared hosting: large packs span many 5-minute ticks; prefer CLI cron on VPS for multi-GB media.
- Pack URL fetch requires public HTTPS (SafeHttp); use `--file` or FTP for private hosts.
- Plain FTP is unencrypted; use FTPS and/or pack encryption.
