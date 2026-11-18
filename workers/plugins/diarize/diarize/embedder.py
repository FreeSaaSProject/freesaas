"""WeSpeaker embedding extractor (ONNX CPU; MLX Metal for ResNet34 only).

Default production model: CAM++-LM ONNX (512-d). MLX backend still loads
ResNet34 from models/mlx/ — do not pair mlx with CAM++ ONNX path.
"""

from __future__ import annotations

import importlib.util
from pathlib import Path
from typing import List, Optional, Sequence

import numpy as np

from .fbank import compute_fbank


def _l2(emb: np.ndarray) -> np.ndarray:
	emb = np.asarray(emb, dtype=np.float32).reshape(-1)
	norm = float(np.linalg.norm(emb))
	if norm > 1e-8:
		emb = emb / norm
	return emb


class WeSpeakerEmbedder:
	"""
	backend:
	  - onnx — onnxruntime CPU (default, portable)
	  - mlx  — Apple Silicon Metal (~10× faster on Studio/M-series)
	"""

	def __init__(
		self,
		onnx_path: Path,
		*,
		sample_rate: int = 16000,
		threads: int = 4,
		backend: str = "onnx",
		mlx_dir: Optional[Path] = None,
	) -> None:
		self.sample_rate = int(sample_rate)
		self.backend = (backend or "onnx").strip().lower()
		self._session = None
		self._mlx_model = None
		self._mx = None

		if self.backend == "mlx":
			self._init_mlx(mlx_dir or Path(onnx_path).parent / "mlx")
		else:
			self._init_onnx(onnx_path, threads=threads)

	def _init_onnx(self, onnx_path: Path, *, threads: int) -> None:
		import onnxruntime as ort

		path = Path(onnx_path)
		if not path.is_file():
			raise FileNotFoundError(f"WeSpeaker ONNX missing: {path}")
		so = ort.SessionOptions()
		so.inter_op_num_threads = max(1, int(threads))
		so.intra_op_num_threads = max(1, int(threads))
		providers = ["CPUExecutionProvider"]
		# Optional experiment: CoreML EP is often wrong for this graph — keep CPU.
		self._session = ort.InferenceSession(
			str(path),
			sess_options=so,
			providers=providers,
		)
		inputs = self._session.get_inputs()
		outputs = self._session.get_outputs()
		self._in_name = inputs[0].name if inputs else "feats"
		self._out_name = outputs[0].name if outputs else "embs"

	def _init_mlx(self, mlx_dir: Path) -> None:
		import mlx.core as mx

		root = Path(mlx_dir)
		weights_path = root / "weights.npz"
		arch_path = root / "resnet_embedding.py"
		if not weights_path.is_file():
			raise FileNotFoundError(f"MLX weights missing: {weights_path}")
		if not arch_path.is_file():
			raise FileNotFoundError(f"MLX arch missing: {arch_path}")

		spec = importlib.util.spec_from_file_location("wespeaker_mlx_resnet", arch_path)
		if spec is None or spec.loader is None:
			raise RuntimeError(f"Cannot import {arch_path}")
		mod = importlib.util.module_from_spec(spec)
		spec.loader.exec_module(mod)
		model = mod.ResNet34Embedding()
		weights = np.load(str(weights_path))
		for key in weights.files:
			path = key.split(".")
			module = model
			for attr in path[:-1]:
				if attr.isdigit():
					module = module[int(attr)]
				elif attr == "layers":
					module = module.layers
				else:
					module = getattr(module, attr)
			setattr(module, path[-1], mx.array(weights[key]))
		model.eval()
		self._mlx_model = model
		self._mx = mx

	def embed_waveform(self, waveform: np.ndarray) -> np.ndarray:
		"""L2-normalized embedding (D,) from mono float32 waveform."""
		wav = np.asarray(waveform, dtype=np.float32).reshape(-1)
		if wav.size < self.sample_rate // 10:
			return np.zeros((0,), dtype=np.float32)
		feats = compute_fbank(wav, sample_rate=self.sample_rate)
		if feats.shape[0] < 5:
			return np.zeros((0,), dtype=np.float32)

		if self.backend == "mlx":
			assert self._mlx_model is not None and self._mx is not None
			mx = self._mx
			batch = mx.array(feats[np.newaxis, :, :].astype(np.float32))
			out = self._mlx_model(batch)
			mx.eval(out)
			return _l2(np.array(out, dtype=np.float32))

		assert self._session is not None
		batch = feats[np.newaxis, :, :].astype(np.float32)
		out = self._session.run([self._out_name], {self._in_name: batch})[0]
		return _l2(np.asarray(out, dtype=np.float32))

	def embed_windows(
		self,
		waveform: np.ndarray,
		windows: Sequence[tuple[float, float]],
	) -> List[np.ndarray]:
		sr = self.sample_rate
		out: List[np.ndarray] = []
		wav = np.asarray(waveform, dtype=np.float32).reshape(-1)
		for t0, t1 in windows:
			a = max(0, int(t0 * sr))
			b = min(wav.size, int(t1 * sr))
			if b - a < sr // 10:
				out.append(np.zeros((0,), dtype=np.float32))
				continue
			out.append(self.embed_waveform(wav[a:b]))
		return out
