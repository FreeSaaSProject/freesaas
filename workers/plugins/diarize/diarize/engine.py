"""End-to-end diarization: VAD → windows → WeSpeaker → cluster → payload."""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence, Tuple

import numpy as np

from .audio_io import load_mono_16k
from .cluster import cluster_embeddings
from .embedder import WeSpeakerEmbedder
from .vad import sliding_windows, speech_regions

_CUE_RE = re.compile(
	r"\[(\d{1,2}):(\d{2})\s*->\s*(\d{1,2}):(\d{2})\]\s*(.*)$"
)


@dataclass
class DiarizeConfig:
	onnx_path: Path
	sample_rate: int = 16000
	window_sec: float = 1.5
	hop_sec: float = 0.75
	min_speech_sec: float = 0.4
	cluster_threshold: float = 0.35
	max_speakers: int = 4
	vad_rms_db: float = -42.0
	threads: int = 4
	backend: str = "onnx"  # onnx | mlx
	mlx_dir: Optional[Path] = None
	# Cue→staff cosine (max over centroid+samples). Overridden by FreeSaaS match_cue.
	match_cue: float = 0.48
	# Min (top1 − top2); 0 = disabled. Overridden by FreeSaaS match_margin.
	match_margin: float = 0.0


@dataclass
class DiarizeResult:
	speakers: List[Dict[str, Any]] = field(default_factory=list)
	segments: List[Dict[str, Any]] = field(default_factory=list)
	cue_speakers: List[Dict[str, Any]] = field(default_factory=list)
	dominant_speaker: str = ""
	duration_sec: float = 0.0
	window_count: int = 0

	def to_payload(self) -> Dict[str, Any]:
		return {
			"speakers": self.speakers,
			"segments": self.segments,
			"cue_speakers": self.cue_speakers,
			"dominant_speaker": self.dominant_speaker,
			"duration_sec": round(self.duration_sec, 3),
			"window_count": self.window_count,
		}


_EMBEDDER_CACHE: Dict[str, WeSpeakerEmbedder] = {}


def _embedder(cfg: DiarizeConfig) -> WeSpeakerEmbedder:
	mlx_key = str(Path(cfg.mlx_dir).resolve()) if cfg.mlx_dir else ""
	key = f"{cfg.backend}:{Path(cfg.onnx_path).resolve()}:{mlx_key}"
	emb = _EMBEDDER_CACHE.get(key)
	if emb is None:
		emb = WeSpeakerEmbedder(
			cfg.onnx_path,
			sample_rate=cfg.sample_rate,
			threads=cfg.threads,
			backend=cfg.backend,
			mlx_dir=cfg.mlx_dir,
		)
		_EMBEDDER_CACHE[key] = emb
	return emb


def config_from_dict(raw: Dict[str, Any], plugin_root: Path) -> DiarizeConfig:
	model = raw.get("model") if isinstance(raw.get("model"), dict) else raw
	onnx = Path(str(model.get("onnx") or "models/voxceleb_CAM++_LM.onnx"))
	if not onnx.is_absolute():
		onnx = (plugin_root / onnx).resolve()
	backend = str(model.get("backend") or "onnx").strip().lower()
	mlx_raw = str(model.get("mlx_dir") or "models/mlx").strip()
	mlx_dir = Path(mlx_raw)
	if not mlx_dir.is_absolute():
		mlx_dir = (plugin_root / mlx_dir).resolve()
	return DiarizeConfig(
		onnx_path=onnx,
		sample_rate=int(model.get("sample_rate") or 16000),
		window_sec=float(model.get("window_sec") or 1.5),
		hop_sec=float(model.get("hop_sec") or 0.75),
		min_speech_sec=float(model.get("min_speech_sec") or 0.4),
		cluster_threshold=float(model.get("cluster_threshold") or 0.35),
		max_speakers=int(model.get("max_speakers") or 4),
		vad_rms_db=float(model.get("vad_rms_db") or -42.0),
		threads=int(model.get("threads") or 4),
		backend=backend,
		mlx_dir=mlx_dir if backend == "mlx" else None,
		match_cue=float(model.get("match_cue") or 0.48),
		match_margin=float(model.get("match_margin") or 0.0),
	)


def parse_cues(transcription: str) -> List[Tuple[int, float, float, str]]:
	"""Return list of (1-based line, t0, t1, text)."""
	out: List[Tuple[int, float, float, str]] = []
	parts = str(transcription or "").split("[")
	num = 0
	for chunk in parts[1:]:
		num += 1
		line = "[" + chunk
		# Prefer regex on the bracket body
		if "]" not in chunk:
			continue
		head, text = chunk.split("]", 1)
		m = re.match(
			r"(\d{1,2}):(\d{2})\s*->\s*(\d{1,2}):(\d{2})",
			head.strip(),
		)
		if not m:
			continue
		t0 = int(m.group(1)) * 60 + int(m.group(2))
		t1 = int(m.group(3)) * 60 + int(m.group(4))
		out.append((num, float(t0), float(t1), text.rstrip("\r\n")))
	return out


def align_cues(
	transcription: str,
	segments: Sequence[Dict[str, Any]],
) -> List[Dict[str, Any]]:
	"""Map each cue line to the speaker with max overlap."""
	cues = parse_cues(transcription)
	mapped: List[Dict[str, Any]] = []
	for line, t0, t1, _text in cues:
		best_spk = ""
		best_ov = 0.0
		for seg in segments:
			s0 = float(seg.get("t0") or 0)
			s1 = float(seg.get("t1") or 0)
			ov = max(0.0, min(t1, s1) - max(t0, s0))
			if ov > best_ov:
				best_ov = ov
				best_spk = str(seg.get("speaker") or "")
		if best_spk:
			mapped.append({"line": line, "speaker": best_spk})
	return mapped


def _best_profile_sim(
	vec: np.ndarray,
	profiles: Sequence[Dict[str, Any]],
) -> Tuple[int, float, float]:
	"""Return (id_admin, best cosine, second-best cosine) via centroid + samples."""
	best_admin = 0
	best_sim = 0.0
	second_sim = 0.0
	v = np.asarray(vec, dtype=np.float32).reshape(-1)
	if v.size < 8:
		return 0, 0.0, 0.0
	for p in profiles:
		admin = int(p.get("id_admin") or 0)
		if admin < 1:
			continue
		cands: List[np.ndarray] = []
		cent = p.get("embedding")
		if isinstance(cent, (list, tuple)) and len(cent) >= 8:
			cands.append(np.asarray(cent, dtype=np.float32).reshape(-1))
		for sample in p.get("samples") or []:
			if isinstance(sample, (list, tuple)) and len(sample) >= 8:
				cands.append(np.asarray(sample, dtype=np.float32).reshape(-1))
		admin_best = 0.0
		for c in cands:
			n = min(v.size, c.size)
			if n < 8:
				continue
			sim = float(np.dot(v[:n], c[:n]))
			if sim > admin_best:
				admin_best = sim
		if admin_best <= 0.0:
			continue
		if admin_best > best_sim:
			second_sim = best_sim
			best_sim = admin_best
			best_admin = admin
		elif admin_best > second_sim:
			second_sim = admin_best
	return best_admin, best_sim, second_sim


def _centroid_of(vecs: Sequence[np.ndarray]) -> np.ndarray:
	stack = np.stack([np.asarray(v, dtype=np.float32).reshape(-1) for v in vecs], axis=0)
	cent = np.mean(stack, axis=0)
	norm = float(np.linalg.norm(cent))
	if norm > 1e-8:
		cent = cent / norm
	return cent.astype(np.float32)


def diarize_file(
	audio_path: Path,
	cfg: DiarizeConfig,
	*,
	transcription: str = "",
	profiles: Optional[Sequence[Dict[str, Any]]] = None,
) -> DiarizeResult:
	wav, duration = load_mono_16k(audio_path, cfg.sample_rate)
	emb = _embedder(cfg)
	cues = parse_cues(transcription) if transcription else []
	profile_list = [p for p in (profiles or []) if isinstance(p, dict)]

	# Preferred path: one embedding per ASR cue (same timeline as UI). Body-worn
	# mics collapse sliding-window clusters; cue-level labels stay turn-aligned.
	if cues:
		cue_windows: List[Tuple[float, float]] = []
		cue_meta: List[Tuple[int, float, float]] = []
		for line, t0, t1, _text in cues:
			a, b = float(t0), float(t1)
			if b <= a:
				b = a + max(0.3, cfg.min_speech_sec)
			# WeSpeaker needs ~1.5s; pad short cues symmetrically within file.
			need = max(cfg.window_sec, cfg.min_speech_sec)
			if (b - a) < need:
				pad = (need - (b - a)) * 0.5
				a = max(0.0, a - pad)
				b = min(duration, b + pad)
				if b <= a:
					b = min(duration, a + need)
			cue_windows.append((a, b))
			cue_meta.append((line, float(t0), float(t1)))

		embeddings = emb.embed_windows(wav, cue_windows)

		# With staff voiceprints: label cues by profile match — never merge everyone into S0.
		if profile_list:
			thr = float(cfg.match_cue)
			margin = max(0.0, float(getattr(cfg, "match_margin", 0.0) or 0.0))
			matched_vecs: Dict[int, List[np.ndarray]] = {}
			matched_talk: Dict[int, float] = {}
			matched_conf: Dict[int, List[float]] = {}
			cue_speakers: List[Dict[str, Any]] = []
			segments: List[Dict[str, Any]] = []
			for (line, t0, t1), vec in zip(cue_meta, embeddings):
				admin, sim, second = _best_profile_sim(vec, profile_list)
				if admin < 1 or sim < thr:
					continue
				if margin > 0.0 and (sim - second) < margin:
					continue
				spk = f"A{admin}"
				cue_speakers.append({"line": line, "speaker": spk, "confidence": round(sim, 4)})
				matched_vecs.setdefault(admin, []).append(np.asarray(vec, dtype=np.float32))
				matched_conf.setdefault(admin, []).append(sim)
				matched_talk[admin] = matched_talk.get(admin, 0.0) + max(0.0, t1 - t0)
				if segments and segments[-1]["speaker"] == spk and t0 - float(segments[-1]["t1"]) <= 0.05:
					segments[-1]["t1"] = round(t1, 3)
				else:
					segments.append({"t0": round(t0, 3), "t1": round(t1, 3), "speaker": spk})

			speakers: List[Dict[str, Any]] = []
			for admin, vecs in matched_vecs.items():
				cent = _centroid_of(vecs)
				confs = matched_conf.get(admin) or [0.0]
				speakers.append(
					{
						"id": f"A{admin}",
						"role": "staff",
						"id_admin": admin,
						"confidence": round(float(sum(confs) / max(1, len(confs))), 4),
						"talk_sec": round(matched_talk.get(admin, 0.0), 3),
						"embedding": [round(float(x), 6) for x in cent.tolist()],
					}
				)
			dominant = ""
			if speakers:
				dominant = max(speakers, key=lambda s: float(s.get("talk_sec") or 0))["id"]
				for s in speakers:
					s["role"] = "staff" if s["id"] == dominant else "other"
					# Keep id_admin for every matched staff bucket (UI badge).
					if int(s.get("id_admin") or 0) > 0:
						s["role"] = "staff"

			return DiarizeResult(
				speakers=speakers,
				segments=segments,
				cue_speakers=cue_speakers,
				dominant_speaker=dominant,
				duration_sec=duration,
				window_count=len(cue_windows),
			)

		labels, centroids = cluster_embeddings(
			embeddings,
			threshold=cfg.cluster_threshold,
			max_speakers=cfg.max_speakers,
		)

		segments = []
		cue_speakers = []
		talk: Dict[int, float] = {}
		for (line, t0, t1), lab in zip(cue_meta, labels):
			if lab < 0:
				continue
			spk = f"S{lab}"
			cue_speakers.append({"line": line, "speaker": spk})
			dur = max(0.0, t1 - t0)
			talk[lab] = talk.get(lab, 0.0) + dur
			if segments and segments[-1]["speaker"] == spk and t0 - float(segments[-1]["t1"]) <= 0.05:
				segments[-1]["t1"] = round(t1, 3)
			else:
				segments.append({"t0": round(t0, 3), "t1": round(t1, 3), "speaker": spk})

		speakers = []
		for i, cent in enumerate(centroids):
			speakers.append(
				{
					"id": f"S{i}",
					"role": "unknown",
					"id_admin": 0,
					"confidence": 0.0,
					"talk_sec": round(talk.get(i, 0.0), 3),
					"embedding": [round(float(x), 6) for x in cent.tolist()],
				}
			)
		dominant = ""
		if speakers:
			by_id = {s["id"]: s for s in speakers}
			speakers = [by_id[f"S{i}"] for i in range(len(centroids)) if f"S{i}" in by_id]
			dominant = max(speakers, key=lambda s: float(s.get("talk_sec") or 0))["id"]
			for s in speakers:
				s["role"] = "staff" if s["id"] == dominant else "other"

		return DiarizeResult(
			speakers=speakers,
			segments=segments,
			cue_speakers=cue_speakers,
			dominant_speaker=dominant,
			duration_sec=duration,
			window_count=len(cue_windows),
		)

	# Fallback without transcript: energy VAD → sliding windows.
	regions = speech_regions(
		wav,
		cfg.sample_rate,
		rms_db=cfg.vad_rms_db,
		min_speech_sec=cfg.min_speech_sec,
	)
	windows = sliding_windows(
		regions,
		window_sec=cfg.window_sec,
		hop_sec=cfg.hop_sec,
	)
	if not windows and duration >= cfg.min_speech_sec:
		windows = sliding_windows(
			[(0.0, duration)],
			window_sec=cfg.window_sec,
			hop_sec=cfg.hop_sec,
		)

	embeddings = emb.embed_windows(wav, windows)
	labels, centroids = cluster_embeddings(
		embeddings,
		threshold=cfg.cluster_threshold,
		max_speakers=cfg.max_speakers,
	)

	segments = []
	for (t0, t1), lab in zip(windows, labels):
		if lab < 0:
			continue
		spk = f"S{lab}"
		if segments and segments[-1]["speaker"] == spk and t0 - float(segments[-1]["t1"]) <= cfg.hop_sec * 1.1:
			segments[-1]["t1"] = round(t1, 3)
		else:
			segments.append({"t0": round(t0, 3), "t1": round(t1, 3), "speaker": spk})

	talk = {}
	for seg in segments:
		lab = int(str(seg["speaker"]).lstrip("S") or -1)
		if lab < 0:
			continue
		talk[lab] = talk.get(lab, 0.0) + max(0.0, float(seg["t1"]) - float(seg["t0"]))

	speakers = []
	for i, cent in enumerate(centroids):
		speakers.append(
			{
				"id": f"S{i}",
				"role": "unknown",
				"id_admin": 0,
				"confidence": 0.0,
				"talk_sec": round(talk.get(i, 0.0), 3),
				"embedding": [round(float(x), 6) for x in cent.tolist()],
			}
		)

	dominant = ""
	if speakers:
		by_id = {s["id"]: s for s in speakers}
		speakers = [by_id[f"S{i}"] for i in range(len(centroids)) if f"S{i}" in by_id]
		dominant = max(speakers, key=lambda s: float(s.get("talk_sec") or 0))["id"]
		for s in speakers:
			s["role"] = "staff" if s["id"] == dominant else "other"

	return DiarizeResult(
		speakers=speakers,
		segments=segments,
		cue_speakers=[],
		dominant_speaker=dominant,
		duration_sec=duration,
		window_count=len(windows),
	)
