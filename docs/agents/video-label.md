# Video labeling (`video_label`)

Web LabelForge-style YOLO labeling with hover-peek for low-confidence predictions.

**Requires:** `video` (storage + infer jobs).

## Enable

```bash
php bin/freesaas.php module enable video --accept-risk
php bin/freesaas.php module enable video_label --accept-risk --format=json
```

## UI

| Path | Role |
|------|------|
| `/video-label` | Projects |
| `/video-label/{id}/workspace` | Canvas: Box / Drag / Null, undo, assist |

**Drag hover-peek:** predictions below project confidence stay hidden until the cursor is over them; click promotes into stored labels.

Infer / batch infer enqueue `video` jobs (`infer_image` / `infer_batch`). Export writes YOLO layout under `var/video/exports/{projectId}/`.

## Tables

`base_video_label_projects`, `base_video_label_images`.
