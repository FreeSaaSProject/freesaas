# Third-party — diarize worker

| Component | License | Notes |
|-----------|---------|-------|
| [WeSpeaker](https://github.com/wenet-e2e/wespeaker) toolkit | **Apache-2.0** | Inference patterns adapted; not vendored as a full tree. |
| `voxceleb_CAM++_LM.onnx` weights | **CC-BY-4.0** (VoxCeleb, as stated by WeSpeaker) | Default 512-d embedder. HF: Wespeaker/wespeaker-voxceleb-campplus-LM. Attribution: https://creativecommons.org/licenses/by/4.0/ |
| `voxceleb_resnet34_LM.onnx` (+ optional MLX `weights.npz`) | **CC-BY-4.0** | Legacy 256-d A/B; MLX is ResNet34-only. |
| onnxruntime, numpy, soundfile, kaldi-native-fbank, requests, urllib3 | Apache-2.0 / BSD / MIT (typical) | Pip deps on the edge host only — not in the PHP tarball. |

FreeSaaS first-party plugin code: AGPL-3.0-or-later ([`LICENSE`](../../../LICENSE)). A commercial FreeSaaS license does **not** remove CC-BY attribution for the ONNX weights.
