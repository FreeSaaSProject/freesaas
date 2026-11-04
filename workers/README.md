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
./workers/bin/freesaas-workers autostart on   # durable: LaunchAgent (macOS)
# manual session: ./workers/bin/freesaas-workers dashboard --open
```

Windows: `workers\bin\freesaas-workers.cmd install` then `autostart on` (Task Scheduler **FreeSaaSWorkers**). Dashboard is **localhost-only** — open `http://127.0.0.1:8766/` in a browser on that PC (AnyDesk/RDP OK; Tailscale IP → 403). Processes started over SSH often die with the session; prefer autostart / `schtasks /Run /TN FreeSaaSWorkers`.

Or: `./workers/bin/freesaas-workers doctor`

| Plugin | Role | Typical OS |
|--------|------|------------|
| `whisper` | Cabin ASR claim/result | Mac (Windows later) |
| `video` | YOLO cameras | Mac (Windows later) |
| `audioloader` | Badge USB → FTP → ingest | Windows (Mac volumes later) |
