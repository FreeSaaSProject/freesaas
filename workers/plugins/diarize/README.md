# Diarize (WeSpeaker)

Mac edge plugin: speaker diarization + embeddings for cabin badge audio.

- **Toolkit:** [WeSpeaker](https://github.com/wenet-e2e/wespeaker) (Apache-2.0)
- **Default weights:** `voxceleb_CAM++_LM.onnx` (512-d, CC-BY-4.0 / VoxCeleb) — see [`THIRD_PARTY.md`](THIRD_PARTY.md)
- **Legacy:** ResNet34-LM ONNX/MLX (256-d) still downloadable for A/B
- **Also hooked from** `whisper` after ASR when `diarize.enabled` is true in whisper `configs/local.json`

## Install

```bash
# Prefer Python 3.11 for the workers venv (onnxruntime wheels)
/opt/homebrew/bin/python3.11 -m venv workers/.venv
./workers/bin/freesaas-workers install
./workers/bin/freesaas-workers download-models   # fetches ONNX if missing
# edit plugins/diarize/configs/local.json — same server + worker_token as whisper
./workers/bin/freesaas-workers start diarize
```

Whisper alone is enough for post-ASR speakers if the ONNX file is present under `plugins/diarize/models/`. The standalone `diarize` worker backfills records that already have transcription but no `base_records_speakers` rows.

## Contract

- Claim: `GET /audio/asr/diarize-claim` → JSON `{file_name, transcription}` or `None`
- Result: `POST /audio/asr/diarize-result` with `file_name`, `speakers`, `segments`, `cue_speakers`, `dominant_speaker`
