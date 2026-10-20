# FreeSaaS video worker

Python outbound poller: Ultralytics YOLO + ffmpeg. Docs: `docs/agents/video-worker.md`.

**License note:** Ultralytics is AGPL-3.0 (see root `THIRD_PARTY.md`). A FreeSaaS commercial license does not cover Ultralytics; use a separate Ultralytics license or omit this worker.

```bash
cp configs/example.json configs/local.json
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python video_worker.py
```

Never commit `configs/local.json` or real tokens.
