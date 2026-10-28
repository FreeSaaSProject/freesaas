# FreeSaaS edge workers

Modular host for **Mac and Windows**: shared **core** (supervisor, dashboard, install, autostart) + **plugins** (`video`, `whisper`, `audioloader`, …).

**Agent / ops runbook:** [`docs/agents/workers.md`](../docs/agents/workers.md) · badge loader: [`docs/agents/audioloader.md`](../docs/agents/audioloader.md)

```bash
# from repo root (macOS / Linux)
python3 -m venv workers/.venv
workers/.venv/bin/pip install -r workers/core/requirements.txt
cp workers/host.example.json workers/host.local.json
# enable plugins in host.local.json, then:
PYTHONPATH=. python3 -m workers.core install
PYTHONPATH=. python3 -m workers.core dashboard --open
```

Windows: `workers\bin\freesaas-workers.cmd install` (uses `workers\.venv\Scripts\python.exe` when present).

Or: `./workers/bin/freesaas-workers doctor`

| Plugin | Role | Typical OS |
|--------|------|------------|
| `whisper` | Cabin ASR claim/result | Mac (Windows later) |
| `video` | YOLO cameras | Mac (Windows later) |
| `audioloader` | Badge USB → FTP → ingest | Windows (Mac volumes later) |
