# asr_dia — ASR with speaker recognition (Apple Silicon)

Nemotron3 → whisper → CAM++ match. Cabin default when every on-shift audio staff has a voice profile; otherwise `whisper`. Enrollment: **Run diarization** on `/audio/{id}` (no profile gate; `asr_model=reuse` if transcript exists).

## Pipeline

1. `speech diarize --engine nemotron3` ([speech-swift](https://github.com/soniqo/speech-swift), Apache-2.0; Nemotron OpenMDW 1.1)
2. ASR: words_align / reuse / reuse_gap / per_turn
3. CAM++ (shared `../diarize/models/voxceleb_CAM++_LM.onnx`, CC-BY-4.0) → match FreeSaaS profiles (unmatched stay `S*`)

Licenses: [`THIRD_PARTY.md`](THIRD_PARTY.md).

## Enable

```bash
cp workers/plugins/asr_dia/configs/example.json workers/plugins/asr_dia/configs/local.json
# host.local.json → enabled_plugins: whisper, diarize (vectorizer), asr_dia
# FreeSaaS /edge-hosts → enable services asr + diarize + asr_dia
```

Local API: `127.0.0.1:18473` → gateway `/v1/asr_dia/*`.

Keep `diarize` as **embed-only** vectorizer. Ops: `docs/agents/asr_dia.md`.
