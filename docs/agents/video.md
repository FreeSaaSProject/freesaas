# Video analytics (`video`)

Camera monitoring by **posts** (N cameras per post), YOLO job queue, schedule gate, interactive train sessions, alerts, retention, Mac worker bridge.

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

UI: `/video/settings` (superadmin). Cron: `video.purge`.

**Classes:** `CLASSES_JSON` is `[{id,name,label?,color},…]`. `name` = stable key; `label` = display (e.g. Russian) on boxes/legend. Textarea: `staff|сотрудник #e6194b`. Worker draws `label ?: name`.

## Posts

Monitoring UI is **post-centric**. Table `base_video_posts`; cameras have `POST_ID`. Dashboard and `/video/posts` show multi-camera thumbs + summed class counts. Alerts/jobs stay on `CAMERA_ID`.

## Schedule (VideoAnal port)

Stored in camera (or post default) `SETTINGS_JSON.schedule`:

| Field | Swift / meaning |
|-------|-----------------|
| `mode` | `always` \| `simple` \| `weekly` |
| `start_hour/minute`, `end_hour/minute` | simple day window |
| `weekly.mon…sun` | `{start_hour,start_minute,end_hour,end_minute,closed?}` |
| `monthly_overrides` | `YYYY-MM-DD` → hours or `{closed:true}` |
| `dataset_shift_count` / `dataset_interval_sec` | dataset collection caps |

PHP: `VideoSchedule::isActive` gates cron `video.monitor_enqueue` (camera schedule, else post schedule).

## Dataset collect (preferred: Mac local)

Realtime JPEG ticks to hosting are **off** by default (`video.train_session_remote_frames=0`, `dataset_remote_ingest=0` in `cfg/modules/video.ini`).

1. On Studio: Video Worker Status → Dataset → RTSP or video file collect → `~/video-worker/datasets/<slug>/{images,labels}`.
2. Upload zip once (panel or `python dataset_collect.py upload --slug=… --project-id=…`) → `POST /video/worker/dataset-ingest`.
3. Refine labels in `/video-label/{id}/workspace`, then enqueue Ultralytics train from Models as before.

## Interactive train (legacy remote frames)

Sessions in `base_video_train_sessions` still exist for UI bookkeeping. Enabling `train_session_remote_frames=1` restores cron `train_session_tick` + `ingestLabeledFrame` (not recommended on shared hosting).

Phases: `solo_class` → `other_class` → `done`. UI `/video/train` points staff to Mac collect + labeling.

**Coverage** during local collect is on the Mac panel (`coverage.json`), not the web heatmap, unless remote frames are re-enabled.

## Agent ↔ user (playbook)

Alpha tone: «модуль экспериментальный; чиним по месту».

1. **Status first:** `GET /agent/video/status` — explain `issues[]` (no token / no worker / no cameras). Never paste RTSP passwords or tokens into chat logs.
2. **Ask only what you cannot invent:** RTSP, `.pt` path on Mac, VPN if off-LAN, approval to regenerate token.
3. **Setup:** `POST /agent/video/setup` with `post_name` + model + camera (+ optional `schedule_mode`). Or UI wizard `/video/wizard/setup`.
4. **Worker:** [workers.md](workers.md) (plugin `video`). Confirm `workers_online >= 1`.
5. **Smoke:** `POST /agent/video/enqueue` → `/video/cameras/{id}/live` or post card.
6. **Train:** create label project with RU labels → `POST /agent/video/session` `action=start` → coach coverage → `phase_other` → `finish` → enqueue train from Models / session page.

## Agent setup (platform token)

```bash
export TOKEN='…'   # app.agent_token — header only
export BASE='https://freesaas.likespa.ru'

curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "$BASE/agent/video/status"

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H 'Content-Type: application/json' \
  "$BASE/agent/video/setup" \
  -d '{
    "post_name": "Pool post",
    "model_name": "spa-detect",
    "weights_path": "/path/best.pt",
    "classes": ["staff|сотрудник #e6194b", "guest|гость #3cb44b"],
    "camera_name": "Cam A",
    "rtsp_url": "rtsp://user:pass@host/ch01/0",
    "schedule_mode": "simple",
    "schedule_start_hour": 10,
    "schedule_end_hour": 21,
    "enabled": 1,
    "interval_sec": 30
  }'

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H 'Content-Type: application/json' \
  "$BASE/agent/video/enqueue" -d '{"camera_id":1,"type":"ondemand"}'

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H 'Content-Type: application/json' \
  "$BASE/agent/video/session" -d '{"action":"start","camera_id":1,"project_id":1,"target_class_id":0,"interval_ms":500}'
```

Also: `GET|POST /agent/video/settings`, session `action=pause|resume|status|finish|phase_other`.

## Staff UI

| Path | Role |
|------|------|
| `/video` | Dashboard (post cards, workers, jobs) |
| `/video/posts` | Posts list / CRUD / multi-cam view |
| `/video/wizard/setup` | Post → cameras → model+RU labels → schedule → smoke |
| `/video/wizard/train` | Interactive train phases |
| `/video/train` | Sessions + coverage heatmap |
| `/video/cameras` | Nested camera CRUD |
| `/video/cameras/{id}/live` | Annotated frame + legend |
| `/video/models` | Register `.pt`, classes with labels, train |
| `/video/alerts` | Alert journal |
| `/video/settings` | Token + retention |

## Cron

- `video.purge` — retention / size cap.
- `video.monitor_enqueue` — `monitor_tick` when worker online + schedule active.
- `video.train_session_enqueue` — `train_session_tick` every minute while sessions `running`.

## Tables

`base_video_workers`, `base_video_models`, `base_video_posts`, `base_video_cameras`, `base_video_jobs`, `base_video_alerts`, `base_video_detections`, `base_video_train_sessions`.

Media: `var/video/`.

## Hooks

Publishes `video.alert.created` (soft `bots.send` consumer).

## Out of v1

Operator lifeguard / zone-closed statuses (separate later). Full WebRTC live.
