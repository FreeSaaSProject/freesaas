# AudioLoader (workers plugin)

AGPL-3.0-or-later — same as FreeSaaS ([`LICENSE`](../../../LICENSE)).

Windows-first USB badge loader: detect dictaphone volumes → encode MP3 → FTP inbox → `GET /audio/jobs/ingest?token=…`.

Agent runbook: [`docs/agents/audioloader.md`](../../../docs/agents/audioloader.md) and [`docs/agents/workers.md`](../../../docs/agents/workers.md).

## Do not vendor

- `lame.exe` / `lame_enc.dll` — obtain separately ([`THIRD_PARTY.md`](THIRD_PARTY.md))
- Live `configs/local.json` / `audioloader.ini` with secrets
- Python Windows installer

## Quick start (agent)

1. FreeSaaS: enable `audio` + `audio_pipeline` (+ `asr` for recognition); set FTP/`audio_inbox` and copy ingest URL from `/audio/settings`.
2. On the PC: enable plugin in `workers/host.local.json` → `"audioloader"`.
3. `./workers/bin/freesaas-workers install` then edit `workers/plugins/audioloader/configs/local.json`.
4. `./workers/bin/freesaas-workers start audioloader` (headless) or `python workers/plugins/audioloader/worker.py --gui`.

## Layout

```
audioloader/     # core package (badge/encode/FTP/webhook/GUI)
worker.py        # workers host entry (headless default)
configs/         # example.json → local.json
var/             # wav/ogg/mp3/backup (runtime)
logs/            # status.json + app log
```
