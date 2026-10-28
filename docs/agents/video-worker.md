# Video Mac worker

**Canonical deploy + observe runbook:** [workers.md](workers.md) (plugin `video` under `workers/plugins/video/`).

Outbound Ultralytics YOLO + ffmpeg. Pairs with module `video` (+ optional `video_label`). Auth: `X-Video-Token`.

```bash
# from repo root after host install
./workers/bin/freesaas-workers start video
./workers/bin/freesaas-workers status video
./workers/bin/freesaas-workers logs video -f
./workers/bin/freesaas-workers dashboard --open
```

Dataset collect, VPN scripts, job types, and troubleshooting live in [workers.md](workers.md) §5 / §7. Product overview: [video.md](video.md).
