# Video labeling (`video_label`)

Web LabelForge-style YOLO labeling with hover-peek for low-confidence predictions.

**Requires:** `video` (storage + infer jobs).

## Enable

```bash
php bin/freesaas.php module enable video --accept-risk
php bin/freesaas.php module enable video_label --accept-risk --format=json
```

## Agent Surface A (platform `X-FreeSaaS-Agent-Token`)

Same auth as `/agent/video/*` — no staff session.

| Op | Method | Path |
|----|--------|------|
| List projects | GET | `/agent/video-label/projects` |
| Create | POST | `/agent/video-label/projects` JSON `name`, `classes` (lines or array), `model_id` |
| Get | GET | `/agent/video-label/project?id=` |
| Update / delete | POST | `/agent/video-label/project` JSON `id`, fields… or `action=delete` |
| Ingest zip / frames | POST | `/agent/video-label/ingest` multipart `project_id`+`zip` **or** JSON `frames[{filename,image_b64,labels}]` |
| List images | GET | `/agent/video-label/images?id=` (`limit`, `offset`) |

Shared hosting gateways often time out ~60s on large bodies — upload **chunks ≤4 MB** (collector does this automatically). Filenames are idempotent (re-upload skips duplicates).

## Agent ops (session JSON)

| Op | Method | Path |
|----|--------|------|
| List projects | GET | `/video-label?format=json` |
| Create | POST | `/video-label/new` `name=` `classes=` (lines `key\|label #hex`) `model_id=` |
| Get / update / delete | GET/POST | `/video-label/{id}/edit` (`action=delete`) |
| Workspace | GET | `/video-label/{id}/workspace?format=json` |
| Infer | POST | workspace `action=infer` `image_id=` or `batch=1` |
| Export YOLO | POST | workspace `action=export` |
| Save labels | POST | workspace `action=save_labels` `image_id=` `labels_json=` |
| Ingest YOLO zip | POST | workspace multipart `action=ingest_zip` `zip=` (images/ + labels/) |

Mac worker twin (no session): `POST /video/worker/dataset-ingest` with `X-Video-Token`, fields `project_id` + `zip` file (max 500 MB; prefer chunked). Agent twin: `POST /agent/video-label/ingest` with `X-FreeSaaS-Agent-Token`.

After export, train from video: `POST /video/models` `action=enqueue_train&project_id=` (ships zip to Mac worker).

**Collect on Mac, label in web:** prefer Studio `dataset_collect.py` / Video Worker Status → Upload zip. Do not stream train JPEGs to hosting (see [video-worker.md](video-worker.md)).

## UI

| Path | Role |
|------|------|
| `/video-label` | Projects |
| `/video-label/{id}/workspace` | Canvas: Box / Drag / Null, undo, assist; upload images or YOLO zip |

**Drag hover-peek:** model infer runs at `detect_conf=0.01` (LabelForge-style) and stores all boxes in `PREDICTIONS_JSON` with confidence. Boxes below project confidence stay hidden until Drag-hover (dashed peek); click promotes into stored labels. Zip ingest writes **labels only** (not a fake prediction cache). Right-click a box → class / delete. Right rail lists stored objects + model cache.

## Classes

`CLASSES_JSON`: `{id, name, label?, color}`. Textarea: `staff|сотрудник #e6194b`. `name` is the YOLO/export key; `label` is UI/box display (Russian OK). Local Mac collect remaps COCO person → target class into YOLO `.txt`; zip ingest uses `ingestLabeledFrame`.

## Tables

`base_video_label_projects`, `base_video_label_images`.
