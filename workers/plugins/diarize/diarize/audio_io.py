"""Load cabin audio as mono float32 @ 16 kHz via ffmpeg."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
from pathlib import Path
from typing import Tuple

import numpy as np


def load_mono_16k(path: Path, sample_rate: int = 16000) -> Tuple[np.ndarray, float]:
	"""
	Return (waveform float32 [-1,1], duration_sec).
	Uses ffmpeg → wav PCM; falls back to soundfile if already wav/flac.
	"""
	path = Path(path)
	if not path.is_file():
		raise FileNotFoundError(str(path))

	ffmpeg = shutil.which("ffmpeg")
	if ffmpeg:
		with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
			tmp_path = Path(tmp.name)
		try:
			cmd = [
				ffmpeg,
				"-y",
				"-i",
				str(path),
				"-ac",
				"1",
				"-ar",
				str(int(sample_rate)),
				"-f",
				"wav",
				str(tmp_path),
			]
			proc = subprocess.run(cmd, capture_output=True, timeout=300)
			if proc.returncode != 0:
				raise RuntimeError(
					f"ffmpeg failed: {(proc.stderr or b'')[:400]!r}"
				)
			return _read_wav(tmp_path, sample_rate)
		finally:
			try:
				tmp_path.unlink(missing_ok=True)
			except OSError:
				pass

	return _read_wav(path, sample_rate)


def _read_wav(path: Path, sample_rate: int) -> Tuple[np.ndarray, float]:
	import soundfile as sf

	data, sr = sf.read(str(path), always_2d=False)
	wav = np.asarray(data, dtype=np.float32)
	if wav.ndim > 1:
		wav = np.mean(wav, axis=1)
	if int(sr) != int(sample_rate) and wav.size > 0:
		# crude linear resample if ffmpeg missing
		duration = wav.size / float(sr)
		n = max(1, int(round(duration * sample_rate)))
		x_old = np.linspace(0.0, 1.0, num=wav.size, endpoint=False)
		x_new = np.linspace(0.0, 1.0, num=n, endpoint=False)
		wav = np.interp(x_new, x_old, wav).astype(np.float32)
		sr = sample_rate
	dur = float(wav.size) / float(sample_rate)
	peak = float(np.max(np.abs(wav))) if wav.size else 0.0
	if peak > 1.0:
		wav = wav / peak
	return wav, dur
