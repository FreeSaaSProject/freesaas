"""Lightweight energy VAD for cabin mono recordings (no pyannote gate)."""

from __future__ import annotations

from typing import List, Tuple

import numpy as np


def speech_regions(
	waveform: np.ndarray,
	sample_rate: int = 16000,
	*,
	frame_ms: float = 30.0,
	hop_ms: float = 10.0,
	rms_db: float = -42.0,
	min_speech_sec: float = 0.4,
	pad_sec: float = 0.15,
	merge_gap_sec: float = 0.35,
) -> List[Tuple[float, float]]:
	"""
	Return [(t0, t1), ...] speech intervals in seconds.
	RMS threshold in dBFS relative to peak=1.0 float waveform.
	"""
	wav = np.asarray(waveform, dtype=np.float32).reshape(-1)
	if wav.size == 0:
		return []
	frame = max(1, int(sample_rate * frame_ms / 1000.0))
	hop = max(1, int(sample_rate * hop_ms / 1000.0))
	thresh = 10.0 ** (rms_db / 20.0)

	flags: List[bool] = []
	for start in range(0, max(1, wav.size - frame + 1), hop):
		chunk = wav[start : start + frame]
		rms = float(np.sqrt(np.mean(chunk * chunk) + 1e-12))
		flags.append(rms >= thresh)

	if not flags:
		return []

	# Frame → time, merge contiguous speech
	raw: List[Tuple[float, float]] = []
	i = 0
	n = len(flags)
	while i < n:
		if not flags[i]:
			i += 1
			continue
		j = i + 1
		while j < n and flags[j]:
			j += 1
		t0 = (i * hop) / sample_rate
		t1 = ((j - 1) * hop + frame) / sample_rate
		raw.append((t0, t1))
		i = j

	if not raw:
		return []

	# Merge close gaps + pad
	merged: List[Tuple[float, float]] = [raw[0]]
	for a, b in raw[1:]:
		pa, pb = merged[-1]
		if a - pb <= merge_gap_sec:
			merged[-1] = (pa, max(pb, b))
		else:
			merged.append((a, b))

	dur = wav.size / float(sample_rate)
	out: List[Tuple[float, float]] = []
	for a, b in merged:
		a2 = max(0.0, a - pad_sec)
		b2 = min(dur, b + pad_sec)
		if b2 - a2 >= min_speech_sec:
			out.append((a2, b2))
	return out


def sliding_windows(
	regions: List[Tuple[float, float]],
	*,
	window_sec: float = 1.5,
	hop_sec: float = 0.75,
	min_window_sec: float = 0.6,
) -> List[Tuple[float, float]]:
	"""Tile speech regions with overlapping windows for embedding."""
	wins: List[Tuple[float, float]] = []
	for a, b in regions:
		if b - a < min_window_sec:
			continue
		t = a
		while t + min_window_sec <= b + 1e-6:
			t1 = min(b, t + window_sec)
			if t1 - t >= min_window_sec:
				wins.append((t, t1))
			if t1 >= b - 1e-6:
				break
			t += hop_sec
	return wins
