# Video analytics (`video`)

Camera monitoring, YOLO job queue, alerts, retention, Mac worker bridge.

**Maturity:** alpha — expect instability; diagnose and repair locally.

## Enable

```bash
php bin/freesaas.php module enable video --accept-risk --format=json
cp modules/video/config.example.ini cfg/modules/video.ini   # then set token via UI
```

Settings UI: `/video/settings` (superadmin) — generates `worker_token`, retention limits.

## Staff UI

| Path | Role |
|------|------|
| `/video` | Dashboard: workers, cameras, jobs |
| `/video/cameras` | Camera CRUD + enqueue monitor / on-demand |
| `/video/cameras/{id}/live` | Latest annotated frame |
| `/video/models` | Register `.pt`, enqueue train |
| `/video/alerts` | Alert journal |
| `/video/settings` | Token + retention + purge |

## Worker

See [video-worker.md](video-worker.md). Client: `workers/video/`.

## Cron

- `video.purge` — retention / size cap (keeps alert media).
- `video.monitor_enqueue` — enqueue `monitor_tick` for enabled cameras when a worker is online.

## Tables

`base_video_workers`, `base_video_models`, `base_video_cameras`, `base_video_jobs`, `base_video_alerts`, `base_video_detections`.

Media files: `var/video/` (not in MySQL).

**Security:** Agent/JSON and `video.view` responses use `VideoCamerasRepository::forPublicView()` — **no `RTSP_URL`**. Credentials stay on the edit form (`video.edit`) and in worker `hello`/job payloads only.

**Retention:** Detections store primary + `EXTRA_PATHS_JSON` (raw/annotated siblings). Live ring trims both `live_*.jpg` and `raw_*.jpg`. Cron also sweeps orphan files under `frames/` and `live/`.

## Hooks

Publishes `video.alert.created` (soft `bots.send` consumer).
