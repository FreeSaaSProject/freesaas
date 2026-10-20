# FreeSaaS video worker

Python outbound poller: Ultralytics YOLO + ffmpeg. Docs: `docs/agents/video-worker.md`.

```bash
cp configs/example.json configs/local.json
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python video_worker.py
```

Never commit `configs/local.json` or real tokens.
