# Video labeling (`video_label`)

Web LabelForge-style YOLO labeling with hover-peek for low-confidence predictions.

**Requires:** `video` (storage + infer jobs).

## Enable

```bash
php bin/freesaas.php module enable video --accept-risk
php bin/freesaas.php module enable video_label --accept-risk --format=json
```

## Agent ops (session JSON)

| Op | Method | Path |
|----|--------|------|
| List projects | GET | `/video-label?format=json` |
| Create | POST | `/video-label/new` `name=` `classes=` `model_id=` |
| Get / update / delete | GET/POST | `/video-label/{id}/edit` (`action=delete`) |
| Workspace | GET | `/video-label/{id}/workspace?format=json` |
| Infer | POST | workspace `action=infer` `image_id=` or `batch=1` |
| Export YOLO | POST | workspace `action=export` |
| Save labels | POST | workspace `action=save_labels` `image_id=` `labels_json=` |

After export, train from video: `POST /video/models` `action=enqueue_train&project_id=` (ships zip to Mac worker).

## UI

| Path | Role |
|------|------|
| `/video-label` | Projects |
| `/video-label/{id}/workspace` | Canvas: Box / Drag / Null, undo, assist |

**Drag hover-peek:** predictions below project confidence stay hidden until the cursor is over them; click promotes into stored labels.

## Tables

`base_video_label_projects`, `base_video_label_images`.
