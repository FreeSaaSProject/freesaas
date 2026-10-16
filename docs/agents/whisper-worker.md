# Whisper cabin worker (Mac / GPU host)

External pull client for cabin dictaphone ASR. Lives in-repo at [`workers/whisper/`](../../workers/whisper/). Polls FreeSaaS, downloads audio from the HTTP file host (`ftp_server`), runs local **whisper.cpp** `whisper-server`, posts transcripts, then triggers stem (keys / collections).

**Not** part of `/cron/tick`. Chunk ASR (`/audio/asr/chunk-*`) is out of scope for this client.

API contract and oper → FreeSaaS map: [audio_pipeline.md](audio_pipeline.md). Token UI: `/audio/settings`. Messenger voice queue (bots) is separate: `/asr/voice-transcribe/worker` — same `X-Audio-Token`, different client.

## Prerequisites (FreeSaaS)

1. Modules enabled (risk ack if alpha/beta): `asr`, `audio`, `audio_dicts`, `audio_pipeline`.
2. Worker token set: open `/audio/settings` (auto-generates if missing) or `cfg/modules/asr.ini` → `[asr] worker_token=…`. Copy the plaintext **once**.
3. Storage: `ftp_server` in the worker JSON must be the public HTTP base that serves `FILENAME` paths from `base_records` (same as `storage.ftp_http_url` / local inbox layout). Claim returns paths like `/audioParsed/2026/…/file.mp3`.
4. Smoke (before starting the Mac client):

```bash
TOKEN='…'   # from /audio/settings
HOST='https://freesaas.example.tld'

curl -sS -H "X-Audio-Token: $TOKEN" "$HOST/audio/asr/claim"
# → plain text FILENAME or None

curl -sS -X POST -H "X-Audio-Token: $TOKEN" "$HOST/audio/jobs/stem"
# → JSON ok / processed / message
```

Health: `php bin/freesaas.php health --module=asr --format=json` and `--module=audio_pipeline`.

## Prerequisites (worker host)

| Need | Notes |
|------|--------|
| Python 3.9+ | `pip install -r workers/whisper/requirements.txt` |
| ffmpeg + ffprobe | PLAYTIME/VOLUME + mp3 retry |
| [whisper.cpp](https://github.com/ggerganov/whisper.cpp) | Build `whisper-server`; download `ggml-large-v3.bin` (+ Silero VAD model if VAD on) |
| Outbound HTTPS | To FreeSaaS host + file HTTP host |

Models are **not** in this repo (large binaries). Keep them under your whisper.cpp `models/` tree.

## Layout in FreeSaaS repo

```
workers/whisper/
  transcriber_client.py      # main loop
  max_notify.py              # optional Max messenger alerts
  requirements.txt
  start_whisper_server.example.sh
  configs/
    example.json             # committed template (no secrets)
    local.json               # gitignored — your secrets
    .gitignore
  README.md                  # pointer here
```

## Deploy (agent checklist)

1. **Clone / update** FreeSaaS on the worker machine (or copy only `workers/whisper/`).
2. **Python deps:**

```bash
cd workers/whisper
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

3. **Config (no secrets in git):**

```bash
cp configs/example.json configs/local.json
# edit local.json:
#   server        = https://<freesaas-host>   (no trailing slash)
#   ftp_server    = https://<http-file-host>  (serves /audioParsed/…)
#   worker_token  = from /audio/settings (not CHANGE_ME)
#   max.token / max.chat_id optional
```

4. **whisper-server launcher:**

```bash
cp start_whisper_server.example.sh start_whisper_server.sh
chmod +x start_whisper_server.sh
# set WHISPER_CPP_DIR to your whisper.cpp build root, or edit paths
export WHISPER_CPP_DIR="$HOME/whisper.cpp"
```

`configs/local.json` → `whisper.start_script` defaults to `start_whisper_server.sh` (resolved relative to `workers/whisper/`).

5. **Start whisper-server** (health must return HTTP 200 on `http://127.0.0.1:17871/health`):

```bash
./start_whisper_server.sh
# or: WHISPER_VAD=0 ./start_whisper_server.sh
```

6. **Start the pull client:**

```bash
python3 transcriber_client.py -c configs/local.json
```

Default config path is `configs/local.json` if you omit `-c`.

## Endpoints the client uses

| Config key | Default path | Role |
|------------|--------------|------|
| `endpoints.get_file` | `/audio/asr/claim` | GET → filename or `None` |
| `endpoints.save_result` | `/audio/asr/result` | POST `file_name`, `result`, optional `PLAYTIME`/`VOLUME` |
| `endpoints.analytics` | `/audio/jobs/stem` | POST after idle with `?day=YYYY-MM-DD` (see below) |

All three send header **`X-Audio-Token`**. Audio bytes are downloaded from `{ftp_server}{FILENAME}` (no worker token).

### Idle stem vs calendar Re-®

| | Whisper idle `stem?day=…` | Calendar **Re-®** |
|--|---------------------------|-------------------|
| Reset day keys/instances | no | yes (`resetDay`) |
| Stem unparsed for the day | yes (batched until pending=0 or budget) | yes |
| Day-scoped collect + SCORE | yes | yes |
| `audio_reports.calendar.reparse.after` → **staff_reports** tickets / DAY_BONUS | yes (`publish=1` default) | yes |

Without `day=`, stem only processes a lookback batch of unparsed records and does **not** publish staff tickets. Day-report UI can still show numbers after a manual Re-® because it reads collections live — tickets are a separate publish step.

Client always appends `day=today`. HTTP 409 `blocked_run` = another stem still holds the lock (do not burn idle quota).

## Loop behaviour

1. Poll claim until a filename appears.
2. Download from `ftp_server`, probe metadata, transcribe via local `/inference` (SRT → custom `[MM:SS->MM:SS]` lines).
3. POST result (or a short error marker so the file leaves the queue).
4. On idle (`None`): up to `analytics.max_runs_per_idle` stem runs with `day=today`, spaced by `min_interval_sec`.
5. Optional Max notify on whisper-down / session summary / novad fallback.
6. If whisper-server crashes (often VAD): optional auto-restart without VAD for that file, then restore VAD.

## Oper migration (short)

| Oper | FreeSaaS |
|------|----------|
| `gens/getFileName.php` | `/audio/asr/claim` |
| `gens/saveFileDescription.php` | `/audio/asr/result` |
| `gens/recordParser3.php` | `/audio/jobs/stem` |

Do **not** point this client at `oper.likespa.ru` anymore.

## Secrets / git

- Never commit `configs/local.json`, real `worker_token`, or Max tokens.
- `configs/.gitignore` ignores everything except `example.json` / `*.example.json`.
- Regenerating the token on `/audio/settings` invalidates all workers until `local.json` is updated.

## Troubleshooting

| Symptom | Check |
|---------|--------|
| HTTP 401 / 503 on claim | Token missing/wrong; open `/audio/settings`; health `asr.worker_token` |
| Claim always `None` | No rows with `DT_TRANSCRIBED < 1`; run ingest `/audio/jobs/ingest` or wait for inbox |
| Download 404 | `ftp_server` base ≠ path prefix of `FILENAME`; fix storage HTTP URL |
| Stem 401 | Same token; POST not GET |
| Client exits code 2 | Local whisper-server dead — fix binary/models, restart client |
| Garbage / CJK garbage | Client already retries higher temperatures; see `garbage_detection` in JSON |

## Related

- [audio_pipeline.md](audio_pipeline.md) — claim/result/stem contract
- [asr.md](asr.md) — shared token + voice-transcribe queue
- [debugging.md](debugging.md) — audio workers section
- [cron.md](cron.md) — why this worker is not in `/cron/tick`
