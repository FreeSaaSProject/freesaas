# Video Mac worker

Outbound Python worker for Ultralytics YOLO + ffmpeg RTSP. Pair with modules `video` + optional `video_label`.

## Setup

1. Enable modules: `php bin/freesaas.php module enable video --accept-risk` then `video_label --accept-risk`.
2. Open `/video/settings` (superadmin) — copy `worker_token` once.
3. On the Mac:

```bash
cd workers/video
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp configs/example.json configs/local.json
# edit server, worker_token, models.default_weights
python video_worker.py -c configs/local.json
```

4. Register a model on `/video/models` (path on worker or upload `.pt`).
5. Add a camera on `/video/cameras` with RTSP URL (reachable after camera-only tunnel if needed).

## Auth

Header `X-Video-Token` (or `?token=`). Not `app.agent_token`.

## Endpoints

| Path | Role |
|------|------|
| `POST /video/worker/hello` | Heartbeat + camera list |
| `GET /video/worker/claim` | Next job |
| `POST /video/worker/result` | Job result (+ optional frame/annotated base64) |
| `GET /video/worker/file?rel=` | Download media under `var/video/` |
| `POST /video/worker/frame` | Live annotated JPEG push |

## Job types

`infer_image`, `infer_batch`, `monitor_tick`, `capture_frame`, `on_demand`, `train`.

## Tunnel (cameras only)

In `configs/local.json` → `tunnel`: set `enabled`, `up_command` / `down_command` with **split routes** (WireGuard `AllowedIPs` = camera subnet). Do not use full-tunnel OS VPN for all Mac traffic.

## Live / Tailscale

- `live.push_enabled`: worker pushes annotated frames to `/video/worker/frame`.
- Tailscale: optional; poll still works. On-demand infer is a normal job from the UI.

## Retention

Configured on `/video/settings`. Cron `video.purge` deletes monitoring frames; keeps alert media when `keep_alert_media=1`.

See also: [video.md](video.md), [video-label.md](video-label.md), [whisper-worker.md](whisper-worker.md) (same outbound pattern).
