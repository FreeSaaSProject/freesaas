# Third-party — asr_dia worker

Optional Apple Silicon edge plugin. Operator installs binaries/models on the host;
nothing below is vendored in the PHP tarball.

| Component | License | Notes |
|-----------|---------|-------|
| [speech-swift](https://github.com/soniqo/speech-swift) CLI (`speech diarize --engine nemotron3`) | **Apache-2.0** | Dia-first engine. Not shipped in FreeSaaS; operator installs (e.g. Homebrew). |
| [Nemotron-3-Diarization](https://huggingface.co/nvidia/Nemotron-3-Diarization) weights (via speech-swift) | **NVIDIA OpenMDW 1.1** | Permissive commercial use under OpenMDW; keep NVIDIA notices on the worker host. A FreeSaaS commercial license does **not** replace OpenMDW. |
| whisper.cpp / GGML models (`large-v3`, optional podlodka-turbo) | whisper.cpp typically **MIT**; model cards vary | Shared with cabin `whisper` plugin; not re-vendored here. |
| WeSpeaker CAM++ ONNX (`../diarize/models/voxceleb_CAM++_LM.onnx`) | Toolkit **Apache-2.0**; weights **CC-BY-4.0** | Shared with embed-only `diarize` vectorizer — see [`../diarize/THIRD_PARTY.md`](../diarize/THIRD_PARTY.md). |
| onnxruntime, numpy, requests, urllib3 | Apache-2.0 / BSD / MIT (typical) | Pip deps in `requirements.txt` on the edge host only. |

FreeSaaS first-party plugin code: AGPL-3.0-or-later ([`LICENSE`](../../../LICENSE)).
