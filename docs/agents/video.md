# Video analytics (`video`)

Camera monitoring, YOLO job queue, alerts, retention, Mac worker bridge.

**Maturity:** alpha — expect instability; diagnose and repair locally, then offer upstream.

## Enable

```bash
php bin/freesaas.php module enable video --accept-risk --format=json
# optional labeling UI:
php bin/freesaas.php module enable video_label --accept-risk --format=json
```

Or platform HTTP: `POST /agent/modules/video/enable` with `accept_risk=1`.

## Settings reference (`cfg/modules/video.ini`)

| Key | Default | Meaning |
|-----|---------|---------|
| `worker_token` | (empty) | Shared secret for `/video/worker/*` (`X-Video-Token`). Generate via UI or `/agent/video/settings` / setup. |
| `retention_days` | `7` | Delete monitoring frames older than N days (`0` = no age purge). |
| `max_storage_mb` | `2048` | Soft cap for monitoring media under `var/video`. |
| `keep_alert_media` | `1` | Keep frames attached to alerts when purging. |
| `latest_frame_ring` | `3` | How many recent live/raw JPEGs to keep per camera. |
| `purge_mode` | `default` | `default` = age + size; `alerts_only` = aggressive (keep alert media only). |

UI: `/video/settings` (superadmin) — each field has help text. Cron: `video.purge`.

**Class colors:** model `CLASSES_JSON` is `[{id,name,color},…]`. Order = YOLO class id. Palette matches `video_label` (`#e6194b`, `#3cb44b`, …). Worker draws boxes in those colors.

## Agent ↔ user (playbook)

Alpha tone: «модуль экспериментальный; чиним по месту».

1. **Status first:** `GET /agent/video/status` — explain `issues[]` in plain language (no token / no worker / no cameras). Do **not** paste RTSP passwords or `worker_token` / `agent_token` into chat logs.
2. **Ask the user only for what you cannot invent:** RTSP URL (or host+creds privately), path to `.pt` on the Mac, VPN name if camera is off-LAN, approval to regenerate token (breaks old workers).
3. **Setup:** prefer `POST /agent/video/setup` (token + model + camera). Return `new_token` once; tell them to put it in Mac `configs/local.json` (or you write it over SSH if they gave access).
4. **Worker:** follow [video-worker.md](video-worker.md). Confirm `workers_online >= 1` on status.
5. **Smoke:** `POST /agent/video/enqueue` `type=ondemand` → user opens `/video/cameras/{id}/live` (colored boxes + legend).
6. **If broken:** CSRF on worker paths (core must exempt `/video/worker/`), HTTP 413 (shrink frames — worker already recompresses), VPN full-tunnel (camera host route via `camera_vpn_up.sh`).

## Agent setup (platform token — preferred on shared hosting)

Surface A (`X-FreeSaaS-Agent-Token`), no staff session:

```bash
export TOKEN='…'   # app.agent_token — header only, never ?token=
export BASE='https://freesaas.likespa.ru'

curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "$BASE/agent/video/status"

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H 'Content-Type: application/json' \
  "$BASE/agent/video/setup" \
  -d '{
    "regenerate_token": 1,
    "model_name": "spa-detect-train-2",
    "weights_path": "/Users/lora/Documents/Exp/runs/detect/train-2/weights/best.pt",
    "classes": ["guest","admin"],
    "camera_name": "Cam 91",
    "rtsp_url": "rtsp://user:pass@192.168.123.91:554/ch01/0",
    "enabled": 1,
    "interval_sec": 30
  }'

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H 'Content-Type: application/json' \
  "$BASE/agent/video/enqueue" -d '{"camera_id":1,"type":"ondemand"}'
```

Also: `GET|POST /agent/video/settings`.

## Agent setup (session JSON)

Same routes as the UI; `?format=json` / `Accept: application/json` + staff session (`video.*`; settings need `SUPERADMIN_ACCESS`).

Ops: `module.json` → `agent.ops` plus Surface A `/agent/video/*`.

**Security:** `video.view` / list / live / status use `forPublicView()` — **no `RTSP_URL`**. Full RTSP only with `video.edit`.

## Staff UI

| Path | Role |
|------|------|
| `/video` | Dashboard (thumbs, worker health, next steps) |
| `/video/status` | Diagnose |
| `/video/cameras` | List + enqueue |
| `/video/cameras/{id}/live` | Annotated frame, class legend, detections table |
| `/video/models` | Register `.pt`, class colors, train |
| `/video/alerts` | Alert journal |
| `/video/settings` | Token + retention + purge (documented fields) |

## Worker

See [video-worker.md](video-worker.md). Client: `workers/video/`.

## Cron

- `video.purge` — retention / size cap (keeps alert media when configured).
- `video.monitor_enqueue` — enqueue `monitor_tick` when a worker is online.

## Tables

`base_video_workers`, `base_video_models`, `base_video_cameras`, `base_video_jobs`, `base_video_alerts`, `base_video_detections`.

Media: `var/video/`.

## Hooks

Publishes `video.alert.created` (soft `bots.send` consumer).
