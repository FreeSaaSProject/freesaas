# FreeSaaS Mac workers

Modular host: shared **core** (supervisor, dashboard, install) + **plugins** (`video`, `whisper`, …).

**Agent / ops runbook:** [`docs/agents/workers.md`](../docs/agents/workers.md)

```bash
# from repo root
python3 -m venv workers/.venv
workers/.venv/bin/pip install -r workers/core/requirements.txt
cp workers/host.example.json workers/host.local.json
# enable plugins, then:
PYTHONPATH=. python3 -m workers.core install
PYTHONPATH=. python3 -m workers.core dashboard --open
```

Or: `./workers/bin/freesaas-workers doctor`
