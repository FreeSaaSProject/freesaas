# Whisper cabin worker

**Canonical deploy + observe runbook:** [workers.md](workers.md) (plugin `whisper` under `workers/plugins/whisper/`).

Outbound pull client: claim → local whisper.cpp `whisper-server` → result → stem. Auth: `X-Audio-Token`. API contract: [audio_pipeline.md](audio_pipeline.md).

```bash
# whisper-server must be healthy on :17871 first
./workers/bin/freesaas-workers start whisper
./workers/bin/freesaas-workers status whisper
./workers/bin/freesaas-workers logs whisper -f   # replaces an open Terminal window
./workers/bin/freesaas-workers dashboard --open
```

Pending-result retry, idle stem, and troubleshooting: [workers.md](workers.md) §6 / §7. Token UI: `/audio/settings`.
