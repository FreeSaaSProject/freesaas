"""Kaldi-compatible fbank for WeSpeaker ONNX (via kaldi-native-fbank)."""

from __future__ import annotations

import numpy as np

try:
	import kaldi_native_fbank as knf
except ImportError as e:  # pragma: no cover
	raise ImportError(
		"kaldi-native-fbank is required for diarize (pip install kaldi-native-fbank)"
	) from e


def compute_fbank(
	waveform: np.ndarray,
	sample_rate: int = 16000,
	num_mel_bins: int = 80,
	frame_length_ms: float = 25.0,
	frame_shift_ms: float = 10.0,
) -> np.ndarray:
	"""
	Return (T, 80) float32 fbank with per-utterance CMN (no CVN), matching
	wespeaker/bin/infer_onnx.py.
	"""
	wav = np.asarray(waveform, dtype=np.float32).reshape(-1)
	# kaldi-native-fbank expects float32 samples in [-1, 1]
	opts = knf.FbankOptions()
	opts.frame_opts.samp_freq = float(sample_rate)
	opts.frame_opts.frame_length_ms = float(frame_length_ms)
	opts.frame_opts.frame_shift_ms = float(frame_shift_ms)
	opts.frame_opts.dither = 0.0
	opts.frame_opts.window_type = "hamming"
	opts.frame_opts.snip_edges = True
	opts.mel_opts.num_bins = int(num_mel_bins)
	opts.mel_opts.low_freq = 20.0
	opts.use_energy = False

	fbank = knf.OnlineFbank(opts)
	fbank.accept_waveform(sample_rate, wav.tolist())
	fbank.input_finished()

	frames = []
	for i in range(fbank.num_frames_ready):
		frames.append(fbank.get_frame(i))
	if not frames:
		return np.zeros((0, num_mel_bins), dtype=np.float32)
	mat = np.stack(frames, axis=0).astype(np.float32)
	# CMN
	mat = mat - np.mean(mat, axis=0, keepdims=True)
	return mat
