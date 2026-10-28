# AudioLoader (badge USB worker)

**Canonical deploy:** [workers.md](workers.md) §11 — plugin `audioloader` under [`workers/plugins/audioloader/`](../../workers/plugins/audioloader/).

Windows-first edge client: USB dictaphone badges → encode MP3 → FTP into FreeSaaS audio inbox → `GET /audio/jobs/ingest?token=…`. License: AGPL-3.0-or-later (plugin `LICENSE` + repo root). LAME/FFmpeg are **not** vendored — [`THIRD_PARTY.md`](../../workers/plugins/audioloader/THIRD_PARTY.md).

## Prefight (FreeSaaS)

1. Enable `audio` + `audio_pipeline` (and ASR stack if recognition is required).
2. `/audio/settings`: configure `[storage]` (local or FTP inbox, `audio_inbox`), ensure worker token exists, **copy Ingest URL**.
3. Register badge serials under `/audio/badges` / assignments as usual.

## Prefight (Windows PC)

| Need | Notes |
|------|--------|
| Python 3.9+ | Prefer workers venv via `freesaas-workers.cmd install` |
| FFmpeg on PATH | Speechmate OGG / CHINA3 |
| LAME | `lame.exe` in plugin dir or on PATH — operator-supplied |
| USB | Removable badge volumes (drive letters) |

## Install

```bash
cd /path/to/freesaas
cp workers/host.example.json workers/host.local.json
# enabled_plugins: ["audioloader"]  (or with whisper on another host)
# shared.server = https://your-freesaas

workers\bin\freesaas-workers.cmd install
# edit workers/plugins/audioloader/configs/local.json:
#   ftp.* = same as FreeSaaS audio FTP / folder = audio_inbox name
#   server + worker_token  OR  post_upload_webhook_url = pasted Ingest URL

workers\bin\freesaas-workers.cmd doctor
workers\bin\freesaas-workers.cmd start audioloader
workers\bin\freesaas-workers.cmd status audioloader
workers\bin\freesaas-workers.cmd logs audioloader -f
```

Optional GUI wizard: `python workers/plugins/audioloader/worker.py --gui`.

Autostart (whole host): `workers\bin\freesaas-workers.cmd autostart on`.

## Contract

```text
Badge USB → MP3 → FTP {ftp_folder} → GET ingest URL → Cron /audio_pipeline.ingest → ASR claim
```

No HTTP multipart upload of audio. Filename patterns must remain parseable by `IngestRunner` (e.g. `EM_CARD_{date}-{time}_NML_{serial}.mp3`).

## Roadmap

| Wave | Scope |
|------|--------|
| MVP | Windows plugin + docs + AGPL + no binary vendors |
| 2 | Mac `/Volumes` disk adapter in the same plugin |
| 3 | `whisper` / `video` on Windows via the same workers host |
