# Backups (`backups`)

Alpha module: scheduled DB + file sets packed into **one** `.tar` / `.tar.enc`, uploaded to FTP/FTPS with retention, optional OpenSSL encryption, and empty-DB deploy.

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

`{ftp_base_path}/{instance}/{YYYY-MM-DD_HH-MM-SS}.tar`  
or `.tar.enc` when encryption is on.

Inside the tar:

- `db.sql.gz`
- `cfg.tar`
- `files-<source>.tar` (optional sources)
- `manifest.json`

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
