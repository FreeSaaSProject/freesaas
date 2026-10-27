# Video Mac worker

Outbound Python worker: Ultralytics YOLO + ffmpeg RTSP. Pairs with module `video` (+ optional `video_label`).

Same pattern as [whisper-worker.md](whisper-worker.md): worker dials out; no inbound ports to the Mac.

## Auth

Header `X-Video-Token` = `video.worker_token` from FreeSaaS. **Not** `app.agent_token`.

## Prod bring-up (Mac Studio example)

Assumes FreeSaaS at `https://freesaas.likespa.ru`, camera `192.168.123.91` only via L2TP **VPN VT**, weights on disk.

```bash
# On Studio (~/video-worker already rsynced from repo workers/video/)
cd ~/video-worker
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

# Secrets: chmod 600
# ~/video-tunnel/vpn.env  → VPN_NAME, VPN_USER, VPN_PASSWORD, VPN_SECRET, CAMERA_HOST
# configs/local.json      → server, worker_token (from POST /agent/video/setup), models.default_weights

chmod +x scripts/camera_vpn_*.sh
# Optional smoke VPN + one frame:
./scripts/camera_vpn_up.sh
/opt/homebrew/bin/ffmpeg -rtsp_transport tcp -i 'rtsp://…' -frames:v 1 -y /tmp/cam.jpg

# Run (foreground) or nohup:
.venv/bin/python -u video_worker.py -c configs/local.json
# nohup .venv/bin/python -u video_worker.py -c configs/local.json >> logs/worker.log 2>&1 &
```

### `configs/local.json` essentials

| Field | Notes |
|-------|--------|
| `server` | `https://freesaas.likespa.ru` (prod) or LAN URL |
| `worker_token` | From `/agent/video/setup` / settings (`new_token`) |
| `verify_ssl` | `true` on real TLS; `false` only for local self-signed + optional `host_header` |
| `models.default_weights` | Absolute `.pt` on the Mac |
| `ffmpeg.bin` | e.g. `/opt/homebrew/bin/ffmpeg` |
| `tunnel.up_command` / `down_command` | `scripts/camera_vpn_up.sh` / `_down.sh` |

Tunnel scripts start named macOS L2TP. macOS “Send all traffic” installs a PPP default (`link#N`) that `route delete` cannot remove — script does `sudo route change default <LAN_GW>` then a **host route** only to `CAMERA_HOST`. If public IP is still therms / default stays on `ppp*`, script **stops the VPN** and exits. Prefer also unchecking System Settings → VPN → **Send all traffic over VPN**. Panel shows **FULL TUNNEL** when default if is `ppp*`.

**Poll politeness (shared hosting):** defaults `sleep_seconds_idle=60`, `hello_every_sec=180`. Aggressive `5`/`20` will trip WAFs. Keep `live.push_enabled=false` on prod.

**Rotate** VPN and worker secrets if they were pasted into chat.

## Dev / generic setup

```bash
cd workers/video
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp configs/example.json configs/local.json
# edit server, worker_token, models.default_weights
python video_worker.py -c configs/local.json
```

## Endpoints

| Path | Role |
|------|------|
| `POST /video/worker/hello` | Heartbeat; returns cameras (+ `classes` colors) and models |
| `GET /video/worker/claim` | Next job |
| `POST /video/worker/result` | Result + optional `frame_b64` / `annotated_b64` |
| `GET /video/worker/file?rel=` | Download under `var/video/` |
| `POST /video/worker/frame` | Live push |

CSRF must exempt `/video/worker/` (platform `Csrf` + route `csrf=>false`).

## Job types

`infer_image`, `infer_batch`, `monitor_tick`, `capture_frame`, `on_demand`, `train`, `train_session_tick`.

Camera jobs include `classes: [{id,name,label?,color}]` — worker draws **label** (fallback name) in class color.

**Train:** server ships `export_zip_rel`; worker downloads zip via `/video/worker/file`. Never pass PHP absolute paths to the Mac.

**Interactive train (`train_session_tick`):** uses `models.stock_weights` (default `yolo11n.pt`, Ultralytics auto-download). Detects COCO `person`, remaps boxes to `target_class_*` from payload, posts frames+labels for `video_label` ingest + coverage update.

## Local status UI (Mac)

Self-contained panel on **localhost only** (`127.0.0.1:8766`). Does not depend on FreeSaaS UI or an open browser tab staying open — the HTTP process keeps running; the shortcut only reopens the URL.

```bash
cd ~/video-worker   # or workers/video in the repo
chmod +x scripts/status_open.sh scripts/install_status_shortcut.sh
./scripts/install_status_shortcut.sh   # Desktop + ~/Applications «Video Worker Status»
./scripts/status_open.sh               # start dashboard if needed + open browser
# or double-click «Video Worker Status.app» / .command
```

| Source | What you see |
|--------|----------------|
| `pgrep` / process list | Worker running? |
| `scutil --nc status` | Named VPN (from `tunnel.vpn_name` / `VPN_NAME`) |
| Default route if | **FULL TUNNEL** warning when `ppp*` |
| `ping` / route | Each camera host (from hello → `status.json` `cameras[]`, plus `CAMERA_HOST` / `CAMERA_HOSTS` in vpn.env) |
| `logs/status.json` | Written by `video_worker.py`: hello OK, cameras (id/name/host, no RTSP secrets), jobs, bytes, last train |
| Optional `agent_token` in `local.json` | Cached ≤120s poll of `GET /agent/video/status` (platform token, **not** worker_token) |

**Controls (localhost POST):** Stop / Start worker, VPN Down, VPN Up (camera-only), Stop worker + VPN.

Env: `VIDEO_STATUS_PORT` (default `8766`), `VIDEO_STATUS_HOST` (default `127.0.0.1`).

## Troubleshooting

| Symptom | Check |
|---------|--------|
| `csrf` 422 on hello | Deploy core/video with CSRF exempt for `/video/worker/` |
| `worker_token not configured` 503 | `/agent/video/setup` or settings |
| `unauthorized` 401 | Mismatch `local.json` vs server token |
| `413` on result | Worker recompresses; raise Apache `SSLRenegBufferSize` / `LimitRequestBody` if still huge |
| No RTSP | VPN up? `ping` camera? Password `@` URL-encoded as `%40` |
| Worker online but stale frames | `POST /agent/video/enqueue`; cron `video.monitor_enqueue` needs online worker |
| Status page blank / connection refused | `./scripts/status_open.sh`; check `logs/status_dashboard.log` |

## Agent ↔ user

- Prefer explaining status from `GET /agent/video/status` over asking the user to dig logs.
- Ask for VPN interactive password / sudo only if split-tunnel route cannot be set; otherwise scripts + Keychain creds.
- After enqueue, point them to `/video/cameras/{id}/live` (legend + colored boxes).

See also: [video.md](video.md), [video-label.md](video-label.md).
