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
| `dispatch_pull_enabled` | `1` | Mac polls `/video/worker/claim` (returns no job when off). |
| `dispatch_push_enabled` | `0` | FreeSaaS sends jobs to edge hosts with the `video` service (`/edge-hosts`) and collects results. |

UI: `/video/settings` (superadmin). Cron: `video.purge`.

**Classes:** `CLASSES_JSON` is `[{id,name,label?,color},…]`. `name` = stable key; `label` = display (e.g. Russian) on boxes/legend. Textarea: `staff|employee #e6194b`. Worker draws `label ?: name`.

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

## Control rules (alerts)

Per camera (`SETTINGS_JSON.rules`), falling back to the post's rules when the camera has none. Evaluated server-side in `VideoResultIngest` on each `monitor_tick` (not `on_demand`) by `VideoRules`; state (streaks, last alert) in `var/video/rules_state/<camera>.json`. Alerts go through `VideoAlertsRepository` → `video.alert.created` → notify/`bots.send`; kind `rule:<type>`.

Text form (`rules_text` in the camera/post form and `video.setup`), one rule per line:

```
absent staff ticks=3 cooldown=30
present guest unless=staff
count_over guest n=5
```

`type class [n=1] [ticks=1] [cooldown=30] [when=class] [unless=class]` — types `present` (count ≥ n), `absent` (count < n), `count_over` (count > n); `class` = id, key or label of the model class; `ticks` = consecutive ticks; `cooldown` = minutes between alerts of that rule. With rules configured, the worker's built-in `no_detection` alert is ignored; without rules it is rate-limited to one per 30 min per camera.

### Oper absence control (port of oper/VideoAnal)

`absent <class> minutes=4 [run=6] [window=5] [bucket=15] [cooldown=10] [fpm=1] [max=10]` switches the rule to the oper chain (`VideoPresence`, `VideoRules::evaluateOper`):

- **Dense sampling.** A rule with `minutes` makes each 5-min monitor tick a batch: the worker (`handle_camera_batch`, one ffmpeg stream) takes `monitor.samples` frames (default 48, every `monitor.sample_interval_sec` = 6 s) and returns `result.samples[{ts,detections,image_b64?}]`; ingest stores one `base_video_detections` row per sample with the sample time.
- **Filter.** Samples → 15-s buckets (present when ≥ half of the samples show the class); an isolated presence between two absences is noise (online `noiseIndices`).
- **Warning.** ≥ `run` absent buckets in a row inside the last `window` minutes, absence span ≥ `minutes`, no alert of the rule for `cooldown` minutes. Kind `rule:absent`, meta `absent_since`, `absence_sec`, `minutes`.
- **Evidence frames** come only from absence frames (the worker uploads at most 2 per minute), ~`fpm` per minute of absence, min 5 when available, max `max` (≤ 10). Frames stay attached to the alert (`FRAME_PATHS_JSON`).
- **Retro filter / report.** `/video/presence?camera_id=&day=` (agent op `video.presence.report`): 30-min intervals present/absent minutes after the oper rules `[1]0[1]`, `[00]1[00]`, `[11]00[11]`.
- Work hours are the camera/post schedule (10:00–21:45: `schedule_mode=simple`, `schedule_end_minute=45`).

### Who is absent: duty attribution and appeals

`workweeks` is required. `settings.duty_status_ids` (camera, else post; `/video/cameras/{id}/edit`) lists the roles responsible for the post. At alert time `WorkweeksRepository::onDutyAt($ts, $statusIds)` resolves the on-shift person (day row in `base_workweeks`, shift window from `PRICE_COL`; several → shortest shift, tie → lowest id) → `base_video_alerts.STAFF_ID` (0 = nobody defined, appeals unavailable). The group message names the on-duty person.

Appeals (`base_video_appeals`, status 0 pending / 1 accepted / 2 rejected): filed by that employee from the bot (cabinet «Absences today» → frames → «Appeal», reason as the next message) or in `/video/alerts`; decided by users with permission `video.appeals.decide` in the bot (notification with frames + Accept/Reject buttons, rejection comment) or in `/video/appeals/{id}`; the employee is told the decision. Accept sets `STATE=1` on the alert. Events: `video.appeal.created`, `video.appeal.decided`. Agent ops: `video.appeals.list|get|create|decide`.

### Modularity matrix

| Module | If off |
|--------|--------|
| `workweeks` | required — module cannot be enabled |
| `bots` / `notify` | no group message / bot appeals; web UI appeals and alerts still work |
| `audio` | no consequences: records are not disabled |
| `audio` on | optional, off by default (`cfg/modules/audio.ini` `[audio] video_effects_*`, `/audio/settings`): absence alert disables the person's records from `absent_since − before` to `alert + after` minutes; accepted appeal re-enables exactly those (`base_audio_video_effects`). Registry on `/video/settings` via `video.consequences.collect`. |

Bot images: `BotSend::enqueue(['images' => [abs paths under var/], …])` (marker `[[IMAGES_JSON]]` in the queue text, ≤ 10; Telegram `sendPhoto`/`sendMediaGroup`, Max upload + attachments; the text with buttons follows the photos). `notify` passes `ctx.images` / `ctx.buttons` of the event. Queue text must not contain emoji (utf8mb3 column).

## Dataset collect (preferred: Mac local)

Realtime JPEG ticks to hosting are **off** by default (`video.train_session_remote_frames=0`, `dataset_remote_ingest=0` in `cfg/modules/video.ini`).

1. On Studio: Video Worker Status → Dataset → RTSP or video file collect → `~/video-worker/datasets/<slug>/{images,labels}`.
2. Upload zip once (panel or `python dataset_collect.py upload --slug=… --project-id=…`) → `POST /video/worker/dataset-ingest`.
3. Refine labels in `/video-label/{id}/workspace`, then enqueue Ultralytics train from Models as before.

## Interactive train (legacy remote frames)

Sessions in `base_video_train_sessions` still exist for UI bookkeeping. Enabling `train_session_remote_frames=1` restores cron `train_session_tick` + `ingestLabeledFrame` (not recommended on shared hosting).

Phases: `solo_class` → `other_class` → `done`. UI `/video/train` points staff to Mac collect + labeling.

**Coverage** during local collect is on the Mac panel (`coverage.json`), not the web heatmap, unless remote frames are re-enabled.

## Dispatch: pull and push

Pull (default): the Mac polls `claim` and posts `result`. Push (Mac with a public IP, [workers.md](workers.md) § 14): `VideoPushDispatcher` leases jobs (`base_video_jobs.CHANNEL='push'`, `EDGE_HOST_ID`, `CLAIMED_UNTIL`) and sends them to `/v1/video/jobs`; results come back via `/v1/video/results` and go through the same `VideoResultIngest` as the pull endpoint. Jobs the host no longer holds return to the queue on the next sync. Both channels may be on.

**Push-only (hosting initiates every connection):** Mac `configs/local.json` → `modes.pull=false, push=true` (no `hello` heartbeat, no `claim`, no `result` calls to hosting) and `POST /agent/video/settings dispatch_pull_enabled=0` (hosting answers `claim` with nothing). Online state then comes from the edge-host sync (`push_hosts_online` in `/agent/video/status`), not from `base_video_workers`. Only operator-started Mac actions (dataset upload) still go Mac → hosting.

Live view: when a host is online, `/video/cameras/{id}/live` polls `/video/cameras/{id}/live-frame` every ~1.5 s while the tab is visible → `POST /v1/video/frame` on the host (fresh RTSP grab + YOLO, not stored). Falls back to the last stored frame after 3 failures.

## Agent ↔ user (playbook)

Alpha tone: "module is experimental; we fix it in place".

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
    "classes": ["staff|employee #e6194b", "guest|guest #3cb44b"],
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

### Absence control + appeals via the platform token

One call configures an existing camera (omitted keys keep current values; `rules_scope=post` stores rules/monitor/duty on the post instead of the camera):

```bash
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H 'Content-Type: application/json' \
  "$BASE/agent/video/setup" -d '{
    "camera_id": 1,
    "schedule_mode": "simple", "schedule_start_hour": 10, "schedule_start_minute": 0,
    "schedule_end_hour": 21, "schedule_end_minute": 45,
    "rules_text": "absent сотрудник minutes=4 run=6 cooldown=10 fpm=1 max=10",
    "monitor_samples": 48, "monitor_interval_sec": 6,
    "duty_status_ids": [2]
  }'
```

- `GET /agent/video/status` → `roles[]` (ids for `duty_status_ids`), `control[]` (effective rules, batch, duty roles, `duty_now`), `appeals` (`pending`, `deciders`), `integrations` (workweeks/bots/notify/audio + audio effects), issues `duty_roles_missing`, `no_duty_now`, `no_appeal_deciders`.
- `GET /agent/video/alerts?camera_id=&limit=&appeal_status=` — alerts (`STAFF_ID`, `STATE` 1 = cancelled) + appeals; `GET /agent/video/presence?camera_id=&day=` — oper-filtered absence report.
- `GET|POST /agent/audio/video-effects` — `video_effects_enabled`, `video_effects_before_min`, `video_effects_after_min` (default off).
- Modules/migrations: `POST /agent/modules/{id}/enable` + `POST /agent/db/migrate/run` (see [remote-api.md](remote-api.md)); `workweeks` is required by `video`.
- **Not reachable by the platform token (by design):** granting `video.appeals.decide` / `video.appeals.view` to staff (session/MCP `core.users.update` or `/users`; SUPERADMIN roles already decide) and deciding/creating appeals (session `agent.ops` `video.appeals.*`, bot buttons, UI).

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
- `video.push_dispatch` — every tick: per edge host status → results + ack → dispatch (also right after interactive enqueues).
- `video.monitor_enqueue` — `monitor_tick` when worker online + schedule active.
- `video.train_session_enqueue` — `train_session_tick` every minute while sessions `running`.

## Tables

`base_video_workers`, `base_video_models`, `base_video_posts`, `base_video_cameras`, `base_video_jobs`, `base_video_alerts`, `base_video_detections`, `base_video_train_sessions`.

Media: `var/video/`.

## Hooks

Publishes `video.alert.created` (soft `bots.send` consumer).

## Out of v1

Operator lifeguard / zone-closed statuses (separate later). Full WebRTC live.
