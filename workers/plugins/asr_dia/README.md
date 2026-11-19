# asr_dia — ASR with speaker recognition (Apple Silicon)

Nemotron3 → whisper → CAM++ match. Used when every on-shift staff with audio analytics has a voice profile; otherwise cabin `whisper` stays the default.

## Pipeline

1. `speech diarize --engine nemotron3` ([speech-swift](https://github.com/soniqo/speech-swift))
2. ASR per turn / words_align / reuse modes
3. CAM++ (shared `../diarize/models/voxceleb_CAM++_LM.onnx`) → match FreeSaaS profiles

## Enable

```bash
cp workers/plugins/asr_dia/configs/example.json workers/plugins/asr_dia/configs/local.json
# host.local.json → enabled_plugins: whisper, diarize (vectorizer), asr_dia
# FreeSaaS /edge-hosts → enable services asr + diarize + asr_dia
```

Local API: `127.0.0.1:18473` → gateway `/v1/asr_dia/*`.

Keep `diarize` plugin running as **embed-only** vectorizer for voice samples (light). Do not enable unused plugins — they hold RAM.

Ops: `docs/agents/asr_dia.md`.
