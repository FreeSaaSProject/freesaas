# Video analytics (`video`)

Camera monitoring, YOLO job queue, alerts, retention, Mac worker bridge.

**Maturity:** alpha — expect instability; diagnose and repair locally.

## Enable

```bash
php bin/freesaas.php module enable video --accept-risk --format=json
# optional labeling UI:
php bin/freesaas.php module enable video_label --accept-risk --format=json
```

Settings UI: `/video/settings` (superadmin) — generates `worker_token`, retention limits.

## Agent setup (session JSON)

Same routes as the UI; send `?format=json` or `Accept: application/json` with a staff session that has `video.*` (settings need `SUPERADMIN_ACCESS`).

1. **Diagnose:** `GET /video/status?format=json` — `issues[]`, `agent_next[]`, workers online, token masked, cameras/models (no RTSP secrets on view ops).
2. **Token:** `POST /video/settings?format=json` with `regenerate_token=1` — copy `new_token` once into Mac `workers/video/configs/local.json`.
3. **Model:** `POST /video/models?format=json` `action=save&name=pilot&weights_path=/path/on/mac/best.pt&classes=admin%0Agust%0Astaff`
4. **Camera:** `POST /video/cameras/new?format=json` with `name`, `rtsp_url`, `model_id`, `enabled=1`
5. **Start worker** on Mac (see [video-worker.md](video-worker.md)); recheck status until `workers_online >= 1`.
6. **Smoke infer:** `POST /video/cameras?format=json` `action=enqueue_ondemand&id={id}` then `GET /video/cameras/{id}/live?format=json`.
7. **Retention:** `POST /video/settings` `run_purge=1` or set `retention_days` / `max_storage_mb` / `purge_mode=alerts_only`.

Ops catalog: `module.json` → `agent.ops` (`video.status`, cameras CRUD/enqueue, models, alerts, settings).

**Security:** `video.view` / list / live / status use `forPublicView()` — **no `RTSP_URL`**. Full RTSP only on `video.cameras.get` (`video.edit`).

## Staff UI

| Path | Role |
|------|------|
| `/video` | Dashboard |
| `/video/status` | Diagnose (agent-friendly) |
| `/video/cameras` | Camera CRUD + enqueue |
| `/video/cameras/{id}/live` | Latest annotated frame |
| `/video/models` | Register `.pt`, enqueue train |
| `/video/alerts` | Alert journal |
| `/video/settings` | Token + retention + purge |

## Worker

See [video-worker.md](video-worker.md). Client: `workers/video/`.

## Cron

- `video.purge` — retention / size cap (keeps alert media).
- `video.monitor_enqueue` — enqueue `monitor_tick` when a worker is online.

## Tables

`base_video_workers`, `base_video_models`, `base_video_cameras`, `base_video_jobs`, `base_video_alerts`, `base_video_detections`.

Media: `var/video/`. Detections track `EXTRA_PATHS_JSON`; live ring trims `live_*` and `raw_*`.

## Hooks

Publishes `video.alert.created` (soft `bots.send` consumer).
