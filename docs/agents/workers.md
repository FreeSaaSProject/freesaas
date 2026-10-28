# FreeSaaS Mac workers (modular host)

Canonical runbook for agents deploying **video** (YOLO) and/or **whisper** (cabin ASR) on a clean Mac. Code: [`workers/`](../../workers/) — shared **core** + **plugins**.

Workers dial **out** to FreeSaaS (no inbound ports to the Mac). Local UI/API is **localhost only**.

Related product docs: [video.md](video.md), [video-label.md](video-label.md), [audio_pipeline.md](audio_pipeline.md), [asr.md](asr.md).

---

## Layout

```
workers/
  host.example.json          → copy to host.local.json
  core/                      # supervisor, dashboard, install, CLI
  plugins/
    video/                   # YOLO + ffmpeg + optional camera VPN
    whisper/                 # claim → whisper.cpp → result → stem
    llm/                     # stub only — do not enable yet
  bin/freesaas-workers
  var/run/  var/log/         # pidfiles + captured stdout
```

CLI (from **repo root**, `PYTHONPATH=.` or use the bin wrapper):

```bash
./workers/bin/freesaas-workers install|doctor|download-models|start|stop|status|logs|dashboard
# equivalent:
PYTHONPATH=. python3 -m workers.core …
```

---

## 1. Prefight — FreeSaaS

Pick plugins from the instance stack:

| Need | Enable modules | Token |
|------|----------------|-------|
| Video / YOLO | `video` (+ optional `video_label`) | `video.worker_token` → header `X-Video-Token`. UI `/video/settings` or `POST /agent/video/setup` (`new_token`) |
| Cabin ASR | `asr`, `audio`, `audio_dicts`, `audio_pipeline` (risk ack if alpha/beta) | `[asr] worker_token` → `X-Audio-Token`. UI `/audio/settings` |

Never paste tokens into chat logs. Copy once into Mac `plugins/<id>/configs/local.json`.

Smoke (server-side) before starting Mac clients:

```bash
# Video
curl -sS -H "X-Video-Token: $VIDEO_TOKEN" -X POST "$HOST/video/worker/hello" \
  -H 'Content-Type: application/json' \
  -d '{"worker_key":"mac","hostname":"test","meta":{}}'

# Whisper
curl -sS -H "X-Audio-Token: $AUDIO_TOKEN" "$HOST/audio/asr/claim"
curl -sS -X POST -H "X-Audio-Token: $AUDIO_TOKEN" "$HOST/audio/jobs/stem"
```

Health: `php bin/freesaas.php health --module=video` / `--module=asr` / `--module=audio_pipeline`.

---

## 2. Prefight — Mac

| Need | Notes |
|------|--------|
| macOS + disk for models | YOLO `.pt`; whisper ggml tens of GB |
| Xcode CLT / Homebrew | `xcode-select --install`; `brew install ffmpeg` |
| Python 3.9+ | `python3 --version` |
| Outbound HTTPS | FreeSaaS + (whisper) HTTP file host |
| whisper.cpp (if whisper) | Build `whisper-server`; models under `$WHISPER_CPP_DIR/models/` |

---

## 3. Install host

```bash
# Clone or rsync FreeSaaS; work from repo root
cd /path/to/freesaas
cp workers/host.example.json workers/host.local.json
# Edit enabled_plugins: ["video"], ["whisper"], or both
# Edit shared.server to the FreeSaaS base URL (no trailing slash)
# Set locale (see below) to match the FreeSaaS instance language

./workers/bin/freesaas-workers install
# creates workers/.venv, pip (core + enabled plugins), copies plugin configs/example → local.json,
# attempts model download where url/source allows, runs doctor
```

### Locale + ASR language (agent must set)

Two separate knobs:

| Knob | Where | Meaning |
|------|--------|---------|
| `locale` | `host.local.json` | Dashboard chrome + worker **log** language (`ru`\|`en`) |
| `whisper.form_params.language` | `plugins/whisper/configs/local.json` | Whisper.cpp **ASR** speech language |

**How to choose defaults for an instance:**

| FreeSaaS stand / UI | `locale` | ASR `language` |
|---------------------|----------|----------------|
| likespa.ru / demoru / Russian UI | `ru` | `ru` |
| demoen / English UI | `en` | `en` |
| Unknown | ask operator; example files use `ru` | same as UI unless operator wants otherwise |

Both are editable later from the dashboard header (UI + ASR selects). Changing ASR restarts the whisper plugin. Changing UI locale reloads the page; restart plugins to refresh log language.

Then edit secrets (**chmod 600**):

| File | Fields |
|------|--------|
| `workers/host.local.json` | `enabled_plugins`, `locale`, `dashboard.port`, `shared.server` / `tls`, optional `notify` (Max and/or Telegram ops alerts) |
| `workers/plugins/video/configs/local.json` | `server`, `worker_token`, `worker_key`, `models.*`, `ffmpeg.bin`, optional `tunnel.*`, `agent_token` |
| `workers/plugins/whisper/configs/local.json` | `server`, `ftp_server`, `worker_token`, `tls.*`, `whisper.form_params.language`, `analytics.stem_timeout_sec` (default 90); legacy `max.*` still read as notify fallback |

### Ops notify (Max / Telegram)

Optional alerts from the whisper plugin (server down, recognition summary, novad fallback). **Off by default** on new hosts (`notify.enabled=false`).

Configure in `host.local.json` → `notify` or the dashboard **Notifications** card (master switch, per-event flags, Max and/or Telegram channels). Saving from the dashboard restarts whisper so the worker reloads settings.

```json
"notify": {
  "enabled": true,
  "events": { "whisper_down": true, "recognition_summary": true, "novad_fallback": true },
  "channels": {
    "max": { "enabled": true, "token": "…", "chat_id": "…" },
    "telegram": { "enabled": false, "bot_token": "…", "chat_id": "…" }
  }
}
```

`install` migrates legacy `plugins/whisper/configs/local.json` → `max{}` into `notify.channels.max` when the host channel is empty. Both channels can be on at once.

`edit_last` (default **true**): soft events `recognition_summary` / `novad_fallback` **edit** the previous message in that chat (Max `PUT /messages`, Telegram `editMessageText`) instead of flooding. `whisper_down` always sends a new message. Last message ids live in `workers/var/notify_state.json`.

Whisper launcher:

```bash
cd workers/plugins/whisper
cp start_whisper_server.example.sh start_whisper_server.sh
chmod +x start_whisper_server.sh
export WHISPER_CPP_DIR="$HOME/whisper.cpp"   # build + models live here
./start_whisper_server.sh                    # health: http://127.0.0.1:17871/health
```

Re-run checks:

```bash
./workers/bin/freesaas-workers doctor
./workers/bin/freesaas-workers download-models
```

### Doctor checklist (interpret fails)

| Check | Fix |
|-------|-----|
| `venv` missing | `install` |
| `ffmpeg` | `brew install ffmpeg` |
| `*.token` CHANGE_ME | paste token into plugin `local.json` |
| `*.server` example.tld | set real FreeSaaS URL |
| `whisper.start_script` | copy example → `start_whisper_server.sh` |
| `whisper.depends.whisper-server` | build whisper.cpp; `WHISPER_CPP_DIR` |
| `video.model.*` ultralytics | first job downloads stock weights into `plugins/video/models/` |

---

## 4. Run & observe (replaces open Terminal)

```bash
./workers/bin/freesaas-workers start          # all enabled
./workers/bin/freesaas-workers start video   # one plugin
./workers/bin/freesaas-workers dashboard --open
```

Dashboard: `http://127.0.0.1:8766/` (localhost only).

| UI | Meaning |
|----|---------|
| Top bar | locale (RU/EN), ASR language (if whisper), host CPU/RAM, Start/Stop all |
| Plugins table | running, **CPU%**, **RSS MB**, **activity.phase + detail**, **stale** if no status heartbeat past idle+90s |
| Live log | tail of `workers/var/log/<plugin>.log` (stdout/stderr of supervised process) |
| Plugin widgets | video: VPN / cameras / jobs; whisper: server health / pending / ASR |

Idle whisper: between claims the client sleeps `loop.sleep_seconds_idle` (default 180) with **heartbeat** every ~15s (`activity` countdown). A multi-minute gap in the log after “waiting Ns” is normal. Stem uses `analytics.stem_timeout_sec` (default **90**, not 600) so a dead host fails fast; inference uses `whisper.inference_timeout_sec` (default 1800).

### Agent observability (no HTML scraping)

```bash
# Structured status (activity + metrics)
./workers/bin/freesaas-workers status
./workers/bin/freesaas-workers status whisper

# Tail like an open terminal
./workers/bin/freesaas-workers logs whisper -f
./workers/bin/freesaas-workers logs video --tail 100

# Same via dashboard HTTP (dashboard must be running)
curl -sS 'http://127.0.0.1:8766/api/status'
curl -sS 'http://127.0.0.1:8766/api/status?plugin=whisper'
curl -sS 'http://127.0.0.1:8766/api/logs?plugin=whisper&tail=200'
```

`activity.phase` examples: `idle`, `claim`, `download`, `transcribe`, `infer`, `train`, `stem`, `hello`, `error`.

---

## 5. Plugin: video

Endpoints: `POST /video/worker/hello`, `GET …/claim`, `POST …/result`, `GET …/file`, optional `…/frame`, `…/dataset-ingest`. Header **`X-Video-Token`**.

Job types: `infer_image`, `infer_batch`, `monitor_tick`, `capture_frame`, `on_demand`, `train`, `train_session_tick`.

**Poll politeness (shared hosting):** `loop.sleep_seconds_idle≥60`, `hello_every_sec≥120–180`. Keep `live.push_enabled=false` on prod.

**Camera VPN (optional):** `plugins/video/scripts/camera_vpn_*.sh` + `~/video-tunnel/vpn.env` (`VPN_NAME`, `CAMERA_HOST`, …). Prefer camera-only route — **not** full-tunnel. Dashboard shows **FULL TUNNEL** when default if is `ppp*`.

**Dataset collect:** prefer local collect then one zip upload:

```bash
cd workers/plugins/video
../../.venv/bin/python dataset_collect.py -c configs/local.json rtsp \
  --slug pool_a --rtsp 'rtsp://…' --interval 2 --target-class-id 0 --classes 'staff,guest'
../../.venv/bin/python dataset_collect.py -c configs/local.json upload --slug pool_a --project-id 3
```

**License:** Ultralytics is AGPL — see root `THIRD_PARTY.md`. Commercial FreeSaaS license does not cover Ultralytics.

---

## 6. Plugin: whisper

Endpoints: `GET /audio/asr/claim`, `POST /audio/asr/result`, `POST /audio/jobs/stem`. Header **`X-Audio-Token`**. Audio bytes from `{ftp_server}{FILENAME}` (no token).

Result ack: HTTP 2xx **and** first body line JSON `true`. Failures keep `plugins/whisper/var/pending_results/` and retry without re-Whisper.

Idle: up to `analytics.max_runs_per_idle` stem runs (`day=today`); HTTP **202** = queued; **409** = lock busy.

Not part of `/cron/tick`. Messenger voice queue (`/asr/voice-transcribe/*`) is a **different** client (same token family).

---

## 7. Troubleshooting

| Symptom | Check |
|---------|--------|
| 401 / 503 claim | Token mismatch; regenerate UI → update `local.json` |
| TLS errors | `tls.verify` / `verify_ssl`; private CA → `tls.ca_bundle` |
| Plugin exits immediately | `logs <id>`; missing config; import error |
| No video jobs | Worker hello? Schedule? `POST /agent/video/enqueue` |
| Whisper exit 2 | whisper-server down — health URL; `restart` start script |
| FULL TUNNEL | VPN Down; uncheck “Send all traffic”; camera host route only |
| Claim always `None` (audio) | No rows `DT_TRANSCRIBED < 1`; ingest |
| Download 404 (audio) | `ftp_server` base vs `FILENAME` prefix |
| High CPU / OOM | `status` RSS; stop other plugin; smaller YOLO / whisper model |
| Dashboard 403 | Must use 127.0.0.1, not LAN IP |

Always start with: `status <plugin>` + `logs <plugin> --tail 80`.

---

## 8. Adding a plugin (e.g. future local LLM)

1. `workers/plugins/<id>/` with `plugin.json`, `worker.py` (own process), `requirements.txt`, `configs/example.json`.
2. Optional `dashboard.py`: `snapshot(ctx)`, `render_html(snap, ctx)`, `handle_action(name, payload, ctx)`.
3. Worker writes `logs/status.json` via `workers.core.status` including **`activity`** (`phase`, `detail`, `started_at`); print important lines to stdout (supervisor captures → `var/log/<id>.log`).
4. Declare `models[]` for `download-models`.
5. Add `<id>` to `host.local.json` → `enabled_plugins`.
6. Document a section in this file.

`plugins/llm/` is a stub illustrating the contract — leave disabled until implemented.

---

## 9. Secrets

- Gitignore: `host.local.json`, `plugins/*/configs/local.json`, `var/log/*`, models, `.venv`.
- Do not echo tokens in agent transcripts; refer to field names only.
- Rotating a token on FreeSaaS invalidates Mac until `local.json` is updated.

---

## 10. Autostart (macOS login)

LaunchAgent `ru.freesaas.workers` runs `bin/freesaas-workers-boot.sh`: `start` all enabled plugins, then keep **dashboard** in foreground (`KeepAlive`).

```bash
./workers/bin/freesaas-workers autostart status
./workers/bin/freesaas-workers autostart on    # install + load LaunchAgent
./workers/bin/freesaas-workers autostart off    # unload + remove plist
```

Same toggle in the dashboard **Autostart** card. State also stored in `host.local.json` → `autostart.enabled`. Logs: `var/log/boot.log`, `launchd.*.log`.

---

## 11. Follow-ups

- Working local-LLM plugin
- Single shared worker token across plugins

---

## Shortcut

```bash
# start dashboard in background and open browser
./workers/bin/freesaas-workers dashboard --open
```

Optional: create a macOS `.command` that `cd`s to the repo and runs the line above.
