"""Dia-first pipeline: Nemotron turns + ASR → WeSpeaker match.

Default ASR mode ``words_align``: one full-file whisper (``-ojf`` words) then
assemble cues by overlapping words onto Nemotron turns. Legacy ``per_turn``
re-runs whisper on each clipped turn (worse sense on short windows).
"""

from __future__ import annotations

import json
import math
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence, Tuple

import numpy as np

PLUGIN_ROOT = Path(__file__).resolve().parent.parent
DIARIZE_ROOT = PLUGIN_ROOT.parent / "diarize"


@dataclass
class AsrDiaResult:
	ok: bool
	error: str = ""
	transcription: str = ""
	speakers: List[Dict[str, Any]] = field(default_factory=list)
	segments: List[Dict[str, Any]] = field(default_factory=list)
	cue_speakers: List[Dict[str, Any]] = field(default_factory=list)
	meta: Dict[str, Any] = field(default_factory=dict)


def _expand(path: str) -> str:
	return os.path.expandvars(os.path.expanduser(str(path or "").strip()))


def _ffmpeg_wav(src: Path, dst: Path, sample_rate: int = 16000) -> None:
	cmd = [
		"ffmpeg",
		"-y",
		"-i",
		str(src),
		"-ac",
		"1",
		"-ar",
		str(sample_rate),
		"-c:a",
		"pcm_s16le",
		str(dst),
	]
	subprocess.run(cmd, check=True, capture_output=True)


def _load_mono_16k(path: Path, sample_rate: int = 16000) -> Tuple[np.ndarray, float]:
	# Prefer diarize plugin loader when available.
	if str(DIARIZE_ROOT) not in sys.path:
		sys.path.insert(0, str(DIARIZE_ROOT))
	from diarize.audio_io import load_mono_16k  # type: ignore

	return load_mono_16k(path, sample_rate)


def _speech_diarize(wav: Path, cfg: Dict[str, Any]) -> List[Dict[str, Any]]:
	dia = cfg.get("dia") if isinstance(cfg.get("dia"), dict) else {}
	engine = str(dia.get("engine") or "nemotron3").strip().lower()
	if engine == "vad_fallback":
		return _vad_fallback_turns(wav, cfg)

	speech_bin = _expand(str(dia.get("speech_bin") or "speech"))
	if not shutil.which(speech_bin) and not Path(speech_bin).is_file():
		raise RuntimeError(f"speech_cli_missing:{speech_bin}")

	backend = str(dia.get("backend") or "coreml").strip().lower()
	cmd = [speech_bin, "diarize", str(wav), "--engine", "nemotron3", "--json"]
	if backend == "mlx":
		cmd.extend(["--nemotron3-backend", "mlx"])
	proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
	if proc.returncode != 0:
		err = (proc.stderr or proc.stdout or "diarize_failed").strip()
		raise RuntimeError(f"speech_diarize:{err[:500]}")
	raw = (proc.stdout or "").strip()
	# CLI may print progress; take last JSON object/array.
	data: Any = None
	for candidate in (raw, raw[raw.find("{") :] if "{" in raw else "", raw[raw.find("[") :] if "[" in raw else ""):
		if not candidate:
			continue
		try:
			data = json.loads(candidate)
			break
		except json.JSONDecodeError:
			continue
	if data is None:
		raise RuntimeError("speech_diarize:bad_json")

	segments: List[Dict[str, Any]] = []
	if isinstance(data, dict):
		segments = list(data.get("segments") or data.get("turns") or [])
	elif isinstance(data, list):
		segments = data
	out: List[Dict[str, Any]] = []
	for seg in segments:
		if not isinstance(seg, dict):
			continue
		raw_spk = seg.get("speakerId") if seg.get("speakerId") is not None else seg.get("speaker")
		if raw_spk is None:
			raw_spk = seg.get("label")
		if isinstance(raw_spk, int) or (isinstance(raw_spk, str) and raw_spk.isdigit()):
			spk = f"S{int(raw_spk)}"
		else:
			spk = str(raw_spk or "S0")
		t0 = float(seg.get("startTime") or seg.get("start") or seg.get("t0") or 0)
		t1 = float(seg.get("endTime") or seg.get("end") or seg.get("t1") or 0)
		if t1 <= t0:
			continue
		out.append({"speaker": spk, "t0": t0, "t1": t1})
	if not out:
		# Short / quiet clips: keep ASR alive with one full-file turn.
		audio, dur = _load_mono_16k(wav)
		_ = audio
		if float(dur) <= 0.05:
			raise RuntimeError("speech_diarize:empty_segments")
		return [{"speaker": "S0", "t0": 0.0, "t1": float(dur)}]
	return out


def _vad_fallback_turns(wav: Path, cfg: Dict[str, Any]) -> List[Dict[str, Any]]:
	"""Smoke path without speech-swift: reuse diarize VAD windows as anonymous turns."""
	if str(DIARIZE_ROOT) not in sys.path:
		sys.path.insert(0, str(DIARIZE_ROOT))
	from diarize.engine import config_from_dict, diarize_file  # type: ignore

	dcfg = config_from_dict({})
	result = diarize_file(wav, dcfg, transcription="", profiles=[])
	out: List[Dict[str, Any]] = []
	for seg in result.segments or []:
		if not isinstance(seg, dict):
			continue
		out.append(
			{
				"speaker": str(seg.get("speaker") or "S0"),
				"t0": float(seg.get("t0") or 0),
				"t1": float(seg.get("t1") or 0),
			}
		)
	if not out:
		# Single full-file turn so ASR still runs.
		audio, dur = _load_mono_16k(wav)
		_ = audio
		out = [{"speaker": "S0", "t0": 0.0, "t1": float(dur)}]
	return out


def _merge_turns(
	turns: Sequence[Dict[str, Any]],
	*,
	min_turn_sec: float,
	merge_gap_sec: float,
) -> List[Dict[str, Any]]:
	ordered = sorted(
		[t for t in turns if float(t.get("t1") or 0) - float(t.get("t0") or 0) >= min_turn_sec],
		key=lambda t: (str(t.get("speaker") or ""), float(t.get("t0") or 0)),
	)
	merged: List[Dict[str, Any]] = []
	for t in ordered:
		spk = str(t.get("speaker") or "S0")
		t0 = float(t["t0"])
		t1 = float(t["t1"])
		if (
			merged
			and merged[-1]["speaker"] == spk
			and t0 - float(merged[-1]["t1"]) <= merge_gap_sec
		):
			merged[-1]["t1"] = t1
		else:
			merged.append({"speaker": spk, "t0": t0, "t1": t1})
	return merged


def _cut_wav(src: Path, dst: Path, t0: float, t1: float) -> None:
	dur = max(0.05, t1 - t0)
	cmd = [
		"ffmpeg",
		"-y",
		"-ss",
		f"{t0:.3f}",
		"-t",
		f"{dur:.3f}",
		"-i",
		str(src),
		"-ac",
		"1",
		"-ar",
		"16000",
		"-c:a",
		"pcm_s16le",
		str(dst),
	]
	subprocess.run(cmd, check=True, capture_output=True)


_TS_LINE = re.compile(
	r"\[(\d{1,2}):(\d{2}(?:\.\d+)?)\s*-->\s*(\d{1,2}):(\d{2}(?:\.\d+)?)\]\s*(.*)$"
)

# Cabin whisper custom lines: ``[MM:SS->MM:SS] text`` (single arrow).
_CABIN_LINE = re.compile(
	r"^\[(\d{1,2}):(\d{2})\s*->\s*(\d{1,2}):(\d{2})\]\s*(.*)$"
)


def _parse_mmss(m: str, s: str) -> float:
	return int(m) * 60 + float(s)


def _parse_cabin_transcription(text: str) -> List[Dict[str, Any]]:
	"""Parse FreeSaaS cabin transcript into ASR-like segments (times as float seconds)."""
	out: List[Dict[str, Any]] = []
	for raw in (text or "").splitlines():
		line = raw.strip()
		if not line:
			continue
		m = _CABIN_LINE.match(line)
		if not m:
			continue
		t0 = _parse_mmss(m.group(1), m.group(2))
		t1 = _parse_mmss(m.group(3), m.group(4))
		body = (m.group(5) or "").strip()
		if not body or t1 < t0:
			continue
		if t1 <= t0:
			t1 = t0 + 1.0
		out.append({"t0": float(t0), "t1": float(t1), "text": body, "words": [], "cabin_line": line})
	return out


def _whisper_bin(cli: Path) -> str:
	if cli.is_file():
		return str(cli)
	which = shutil.which(str(cli))
	if which:
		return which
	raise RuntimeError(f"whisper_cli_missing:{cli}")


def _whisper_turn(
	cli: Path,
	model: Path,
	clip: Path,
	*,
	language: str,
	threads: int,
	abs_t0: float,
	asr_cfg: Optional[Dict[str, Any]] = None,
) -> List[Dict[str, Any]]:
	"""ASR one clipped turn → list of {t0,t1,text} in absolute file time."""
	bin_path = _whisper_bin(cli)
	if not model.is_file():
		raise RuntimeError(f"whisper_model_missing:{model}")
	cmd = [
		bin_path,
		"-m",
		str(model),
		"-f",
		str(clip),
		"-l",
		language,
		"-osrt",
		"-of",
		str(clip.with_suffix("")),
		"-np",
	]
	# Beam / no-speech like cabin; skip VAD on short clips (already cut to speech-ish).
	cfg = asr_cfg if isinstance(asr_cfg, dict) else {}
	cmd.extend(
		[
			"-t",
			str(max(1, int(cfg.get("threads") or threads))),
			"-bs",
			str(max(1, int(cfg.get("beam_size") or 8))),
			"-bo",
			str(max(1, int(cfg.get("best_of") or 1))),
			"-nth",
			str(float(cfg.get("no_speech_thold") or 0.35)),
			"-mc",
			"0",
			"-nf",
			"-fa",
			"-sow",
		]
	)
	proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
	srt = clip.with_suffix(".srt")
	cues: List[Dict[str, Any]] = []
	if srt.is_file():
		cues = _parse_srt(srt, abs_t0)
	if not cues:
		cues = _parse_whisper_stdout(proc.stdout or "", abs_t0)
	if not cues:
		text = (proc.stdout or "").strip().splitlines()
		plain = " ".join(x for x in text if x and not x.startswith("["))[:500]
		if plain:
			cues = [{"t0": abs_t0, "t1": abs_t0 + 1.0, "text": plain}]
	return cues


def _cabin_cli_args(asr: Dict[str, Any]) -> List[str]:
	"""Mirror Mac cabin whisper-server decode flags on whisper-cli when server is down."""
	# From workers/plugins/whisper start_whisper_server.sh COMMON_ARGS (+ optional VAD).
	args: List[str] = []
	extra = asr.get("cli_args")
	if isinstance(extra, list) and extra:
		return [str(x) for x in extra]
	beam = int(asr.get("beam_size") or 8)
	best_of = int(asr.get("best_of") or 1)
	threads = int(asr.get("threads") or 8)
	args.extend(
		[
			"-t",
			str(max(1, threads)),
			"-bs",
			str(max(1, beam)),
			"-bo",
			str(max(1, best_of)),
			"-sow",
			"-nth",
			str(float(asr.get("no_speech_thold") or 0.48)),
			"-mc",
			str(int(asr.get("max_context") if asr.get("max_context") is not None else 0)),
			"-ac",
			str(int(asr.get("audio_ctx") or 0)),
			"-et",
			str(float(asr.get("entropy_thold") or 2.4)),
			"-nf",  # no temperature fallback
			"-fa",  # flash attn
			"-tp",
			str(float(asr.get("temperature") or 0.0)),
			"-tpi",
			str(float(asr.get("temperature_inc") or 0.0)),
		]
	)
	vad_model = _expand(str(asr.get("vad_model") or ""))
	if vad_model and Path(vad_model).is_file():
		args.extend(
			[
				"--vad",
				"-vm",
				vad_model,
				"-vt",
				str(float(asr.get("vad_threshold") or 0.20)),
				"-vspd",
				str(int(asr.get("vad_min_speech_ms") or 130)),
				"-vsd",
				str(int(asr.get("vad_min_silence_ms") or 0)),
				"-vp",
				str(int(asr.get("vad_speech_pad_ms") or 200)),
				"-vo",
				str(float(asr.get("vad_samples_overlap") or 0.0)),
			]
		)
	return args


def _whisper_server_srt(
	audio_path: Path,
	*,
	server_url: str,
	form_params: Dict[str, str],
	timeout_sec: float = 1800.0,
	abs_t0: float = 0.0,
) -> List[Dict[str, Any]]:
	"""ASR via local cabin whisper-server (full file or clipped turn).

	``abs_t0`` shifts clip-relative SRT times onto the parent timeline.
	"""
	try:
		import requests  # type: ignore
	except ImportError as e:
		raise RuntimeError("requests_missing_for_whisper_server") from e
	url = server_url.strip() or "http://127.0.0.1:17871/inference"
	data = dict(form_params)
	data.setdefault("language", "ru")
	data.setdefault("task", "transcribe")
	data.setdefault("response_format", "srt")
	data.setdefault("temperature", "0.0")
	data.setdefault("temperature_inc", "0.0")
	mime = "audio/wav" if audio_path.suffix.lower() in (".wav", ".wave") else "audio/mpeg"
	with audio_path.open("rb") as f:
		resp = requests.post(
			url,
			files={"file": (audio_path.name, f, mime)},
			data=data,
			timeout=timeout_sec,
		)
	if resp.status_code != 200:
		raise RuntimeError(f"whisper_server_http:{resp.status_code}:{resp.text[:300]}")
	srt_text = (resp.text or "").strip()
	if "-->" not in srt_text:
		raise RuntimeError(f"whisper_server_not_srt:{srt_text[:200]}")
	# Parse via temp file to reuse _parse_srt.
	tmp = audio_path.with_suffix(".cabin.srt")
	tmp.write_text(srt_text + "\n", encoding="utf-8")
	try:
		segs = _parse_srt(tmp, float(abs_t0))
	finally:
		try:
			tmp.unlink()
		except OSError:
			pass
	if not segs:
		raise RuntimeError("whisper_server_empty_srt")
	# Normalize to assemble shape (no words → midpoint assign only).
	return [{"t0": s["t0"], "t1": s["t1"], "text": s["text"], "words": []} for s in segs]


def _whisper_full_ojf(
	cli: Path,
	model: Path,
	wav: Path,
	*,
	language: str,
	threads: int,
	out_prefix: Path,
	asr_cfg: Optional[Dict[str, Any]] = None,
) -> Path:
	"""Run full-file whisper-cli ``-ojf`` with cabin-like decode flags; return JSON path."""
	bin_path = _whisper_bin(cli)
	if not model.is_file():
		raise RuntimeError(f"whisper_model_missing:{model}")
	out_prefix.parent.mkdir(parents=True, exist_ok=True)
	asr_cfg = asr_cfg if isinstance(asr_cfg, dict) else {}
	# threads come from cabin args; keep language/model explicit.
	cmd = [
		bin_path,
		"-m",
		str(model),
		"-f",
		str(wav),
		"-l",
		language,
		"-ojf",
		"-of",
		str(out_prefix),
		"-np",
	]
	# Prefer explicit cabin mirror; fall back to simple -t/-sow.
	cabin = _cabin_cli_args({**asr_cfg, "threads": threads})
	cmd.extend(cabin if cabin else ["-t", str(max(1, threads)), "-sow"])
	proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
	json_path = Path(str(out_prefix) + ".json")
	if not json_path.is_file():
		alt = wav.with_suffix(wav.suffix + ".json")
		if alt.is_file():
			json_path = alt
	if not json_path.is_file():
		err = (proc.stderr or proc.stdout or "no_json")[:400]
		raise RuntimeError(f"whisper_ojf_missing:{err}")
	return json_path


def _token_is_special(text: str) -> bool:
	s = text.strip()
	return (not s) or s.startswith("[") or s.startswith("<|") or s.startswith("|>")


def _parse_whisper_ojf_units(path: Path) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
	"""Parse ojf → (segments with clean text, word units for speaker cuts).

	Segment ``text`` is taken from whisper's own joined string (cabin-quality).
	Words are only used to split a segment that spans two Nemotron speakers.
	"""
	raw = json.loads(path.read_text(encoding="utf-8", errors="replace"))
	transcription = raw.get("transcription") if isinstance(raw, dict) else None
	if not isinstance(transcription, list):
		transcription = []
	segments: List[Dict[str, Any]] = []
	words: List[Dict[str, Any]] = []
	for seg in transcription:
		if not isinstance(seg, dict):
			continue
		off = seg.get("offsets") if isinstance(seg.get("offsets"), dict) else {}
		t0 = float(off.get("from") or 0) / 1000.0
		t1 = float(off.get("to") or 0) / 1000.0
		if t1 < t0:
			t0, t1 = t1, t0
		text = str(seg.get("text") or "").strip()
		seg_words: List[Dict[str, Any]] = []
		tokens = seg.get("tokens") if isinstance(seg.get("tokens"), list) else []
		# Build display words by concatenating raw token pieces (preserve whisper spacing).
		buf = ""
		buf_t0 = 0.0
		buf_t1 = 0.0
		for tok in tokens:
			if not isinstance(tok, dict):
				continue
			raw_t = str(tok.get("text") or "")
			if _token_is_special(raw_t):
				continue
			toff = tok.get("offsets") if isinstance(tok.get("offsets"), dict) else {}
			wt0 = float(toff.get("from") or 0) / 1000.0
			wt1 = float(toff.get("to") or 0) / 1000.0
			if wt1 < wt0:
				wt0, wt1 = wt1, wt0
			starts = raw_t[:1] in (" ", "\n", "\t") or raw_t.startswith("\u2581")
			piece = raw_t.replace("\u2581", "").strip() if raw_t.startswith("\u2581") else raw_t
			# Leading-space token → flush previous word.
			if starts and buf.strip():
				seg_words.append({"t0": buf_t0, "t1": buf_t1, "text": buf.strip()})
				buf = piece.lstrip() if starts else piece
				buf_t0 = wt0
				buf_t1 = wt1
			elif not buf:
				buf = piece.lstrip() if starts else piece
				buf_t0 = wt0
				buf_t1 = wt1
			else:
				# Continuation: glue as whisper did (piece may include leading space).
				if starts:
					seg_words.append({"t0": buf_t0, "t1": buf_t1, "text": buf.strip()})
					buf = piece.lstrip()
					buf_t0 = wt0
					buf_t1 = wt1
				else:
					buf += piece
					buf_t1 = max(buf_t1, wt1)
		if buf.strip():
			seg_words.append({"t0": buf_t0, "t1": buf_t1, "text": buf.strip()})
		if text:
			segments.append({"t0": t0, "t1": max(t0, t1), "text": text, "words": seg_words})
		words.extend(seg_words)
	return segments, words


def _assign_speaker(
	mid: float,
	turns: Sequence[Dict[str, Any]],
	*,
	pad_sec: float = 0.05,
	max_orphan_sec: float = 1e9,
) -> str:
	"""Map a midpoint onto a Nemotron turn. Always nearest (never drop ASR text)."""
	if not turns:
		return "S0"
	best = ""
	best_dist = 1e9
	for turn in turns:
		spk = str(turn.get("speaker") or "S0")
		t0 = float(turn["t0"]) - pad_sec
		t1 = float(turn["t1"]) + pad_sec
		if t0 <= mid <= t1:
			return spk
		if mid < t0:
			dist = t0 - mid
		else:
			dist = mid - t1
		if dist < best_dist:
			best_dist = dist
			best = spk
	if best and best_dist <= max_orphan_sec:
		return best
	return best or str(turns[0].get("speaker") or "S0")


def _assemble_cues_from_asr(
	asr_segments: Sequence[Dict[str, Any]],
	turns: Sequence[Dict[str, Any]],
	*,
	gap_split_sec: float = 0.75,
	max_cue_sec: float = 14.0,
) -> List[Dict[str, Any]]:
	"""Map full-file ASR segments onto Nemotron turns; split on speaker change via words."""
	cues: List[Dict[str, Any]] = []

	def emit(t0: float, t1: float, text: str, spk: str) -> None:
		text = text.strip()
		if not text or not spk:
			return
		cues.append({"t0": t0, "t1": max(t0, t1), "text": text, "speaker": spk})

	for seg in asr_segments:
		t0 = float(seg["t0"])
		t1 = float(seg["t1"])
		text = str(seg.get("text") or "").strip()
		if not text:
			continue
		words = seg.get("words") if isinstance(seg.get("words"), list) else []
		mid = 0.5 * (t0 + t1)
		spk_all = _assign_speaker(mid, turns)
		# Single-speaker segment (or no word detail): keep whisper text intact.
		spk_counts: Dict[str, int] = {}
		for w in words:
			ws = _assign_speaker(0.5 * (float(w["t0"]) + float(w["t1"])), turns)
			if ws:
				spk_counts[ws] = spk_counts.get(ws, 0) + 1
		# Need a real second voice (≥2 words) before splitting — otherwise keep
		# whisper segment text intact (avoids syllable-level cue spam).
		ranked = sorted(spk_counts.items(), key=lambda kv: kv[1], reverse=True)
		if len(ranked) < 2 or ranked[1][1] < 2 or not words:
			dom = ranked[0][0] if ranked else spk_all
			emit(t0, t1, text, dom or spk_all)
			continue
		# Split on speaker changes using word stream; rebuild text from word pieces.
		buf: List[str] = []
		buf_spk = ""
		buf_t0 = 0.0
		buf_t1 = 0.0
		for w in words:
			wt0 = float(w["t0"])
			wt1 = float(w["t1"])
			wspk = _assign_speaker(0.5 * (wt0 + wt1), turns)
			wtext = str(w.get("text") or "").strip()
			if not wspk or not wtext:
				continue
			if not buf:
				buf = [wtext]
				buf_spk = wspk
				buf_t0 = wt0
				buf_t1 = wt1
				continue
			gap = wt0 - buf_t1
			dur = wt1 - buf_t0
			if wspk != buf_spk or gap >= gap_split_sec or dur >= max_cue_sec:
				emit(buf_t0, buf_t1, " ".join(buf), buf_spk)
				buf = [wtext]
				buf_spk = wspk
				buf_t0 = wt0
				buf_t1 = wt1
			else:
				buf.append(wtext)
				buf_t1 = max(buf_t1, wt1)
		if buf:
			emit(buf_t0, buf_t1, " ".join(buf), buf_spk)
	return cues


def _parse_srt(path: Path, abs_t0: float) -> List[Dict[str, Any]]:
	raw = path.read_text(encoding="utf-8", errors="replace")
	blocks = re.split(r"\n\s*\n", raw.strip())
	out: List[Dict[str, Any]] = []
	for block in blocks:
		lines = [ln.strip() for ln in block.splitlines() if ln.strip()]
		if len(lines) < 2:
			continue
		# 00:00:01,000 --> 00:00:02,000
		m = re.search(
			r"(\d+):(\d+):(\d+)[,.](\d+)\s*-->\s*(\d+):(\d+):(\d+)[,.](\d+)",
			lines[1] if not lines[0][0].isdigit() or "-->" in lines[1] else lines[0],
		)
		if not m:
			# try any line
			for ln in lines:
				m = re.search(
					r"(\d+):(\d+):(\d+)[,.](\d+)\s*-->\s*(\d+):(\d+):(\d+)[,.](\d+)",
					ln,
				)
				if m:
					break
		if not m:
			continue
		t0 = int(m.group(1)) * 3600 + int(m.group(2)) * 60 + int(m.group(3)) + int(m.group(4)) / 1000.0
		t1 = int(m.group(5)) * 3600 + int(m.group(6)) * 60 + int(m.group(7)) + int(m.group(8)) / 1000.0
		text_lines = [ln for ln in lines if "-->" not in ln and not ln.isdigit()]
		text = " ".join(text_lines).strip()
		if not text:
			continue
		out.append({"t0": abs_t0 + t0, "t1": abs_t0 + t1, "text": text})
	return out


def _parse_whisper_stdout(stdout: str, abs_t0: float) -> List[Dict[str, Any]]:
	out: List[Dict[str, Any]] = []
	for line in stdout.splitlines():
		m = _TS_LINE.search(line.strip())
		if not m:
			continue
		t0 = _parse_mmss(m.group(1), m.group(2))
		t1 = _parse_mmss(m.group(3), m.group(4))
		text = (m.group(5) or "").strip()
		if text:
			out.append({"t0": abs_t0 + t0, "t1": abs_t0 + t1, "text": text})
	return out


def _mmss_floor(x: float) -> str:
	"""Cabin whisper format: ``MM:SS`` floored, zero-padded (see whisper worker)."""
	t = max(0, int(math.floor(x)))
	m, s = divmod(t, 60)
	return f"{m:02d}:{s:02d}"


def _mmss_ceil(x: float) -> str:
	"""Cabin whisper format: ``MM:SS`` ceiled, zero-padded (see whisper worker)."""
	t = max(0, int(math.ceil(x)))
	m, s = divmod(t, 60)
	return f"{m:02d}:{s:02d}"


def _sec_to_mmss(sec: int) -> str:
	t = max(0, int(sec))
	m, s = divmod(t, 60)
	return f"{m:02d}:{s:02d}"


def _format_cue(t0: float, t1: float, text: str) -> str:
	"""Same line shape as cabin whisper: ``[MM:SS->MM:SS] text`` (floor/ceil, min 1s)."""
	start = max(0.0, float(t0))
	end = max(0.0, float(t1))
	# Match workers/plugins/whisper/worker.py srt_text_to_custom.
	if end - start < 1.0:
		end = start + 1.0
	return f"[{_mmss_floor(start)}->{_mmss_ceil(end)}] {text.strip()}"


def _sequentialize_cues(
	cues: Sequence[Dict[str, Any]],
	*,
	min_dur: float = 0.35,
) -> List[Dict[str, Any]]:
	"""Clip overlapping float bounds so each cue starts at/after the previous end.

	Whisper SRT and per-cue energy tighten routinely leave 0.5–2s overlaps; cabin
	playback highlight and humans both expect non-overlapping sequential clocks.
	"""
	if len(cues) < 2:
		return [dict(c) for c in cues]
	# Stable by original order when clocks tie (do not alphabetize text).
	ordered = [
		cues[i]
		for i, _ in sorted(
			enumerate(cues),
			key=lambda ic: (float(ic[1]["t0"]), float(ic[1]["t1"]), ic[0]),
		)
	]
	out: List[Dict[str, Any]] = []
	for seg in ordered:
		cur = dict(seg)
		c0 = float(cur["t0"])
		c1 = max(c0 + min_dur, float(cur["t1"]))
		cur["t0"], cur["t1"] = c0, c1
		if not out:
			out.append(cur)
			continue
		prev = out[-1]
		p0 = float(prev["t0"])
		p1 = float(prev["t1"])
		if c0 < p1:
			span = c1 - p0
			if span >= (2.0 * min_dur):
				mid = 0.5 * (c0 + p1)
				mid = min(max(mid, p0 + min_dur), c1 - min_dur)
			else:
				mid = p0 + max(min_dur, span * 0.5)
			prev["t1"] = mid
			cur["t0"] = mid
			cur["t1"] = max(mid + min_dur, c1)
		out.append(cur)
	return out


def _format_cues_sequential(
	cues: Sequence[Dict[str, Any]],
) -> List[Tuple[str, Dict[str, Any]]]:
	"""Cabin ``[MM:SS->MM:SS]`` lines with non-overlapping integer clocks.

	Floor/ceil alone re-introduces 1s display overlaps even when floats abut;
	this pass splits overlaps on the displayed second grid. Returns
	``(formatted_line, cue)`` pairs so speaker metadata stays aligned.
	"""
	rows: List[List[Any]] = []
	for c in cues:
		text = str(c.get("text") or "").strip()
		if not text:
			continue
		s = max(0, int(math.floor(float(c["t0"]))))
		e = max(0, int(math.ceil(float(c["t1"]))))
		if e <= s:
			e = s + 1
		rows.append([s, e, text, dict(c)])

	for i in range(1, len(rows)):
		ps, pe = int(rows[i - 1][0]), int(rows[i - 1][1])
		cs, ce = int(rows[i][0]), int(rows[i][1])
		if cs >= pe:
			continue
		mid = (cs + pe) // 2
		if mid <= ps:
			mid = ps + 1
		if mid >= ce and ce > cs + 0:
			mid = max(ps + 1, ce - 1)
		rows[i - 1][1] = mid
		rows[i][0] = mid
		if int(rows[i][1]) <= int(rows[i][0]):
			rows[i][1] = int(rows[i][0]) + 1
		if int(rows[i - 1][1]) <= int(rows[i - 1][0]):
			rows[i - 1][1] = int(rows[i - 1][0]) + 1
			rows[i][0] = int(rows[i - 1][1])
			if int(rows[i][1]) <= int(rows[i][0]):
				rows[i][1] = int(rows[i][0]) + 1

	out: List[Tuple[str, Dict[str, Any]]] = []
	for s, e, text, cue in rows:
		cue = dict(cue)
		cue["t0"] = float(s)
		cue["t1"] = float(e)
		cue["text"] = text
		out.append((f"[{_sec_to_mmss(int(s))}->{_sec_to_mmss(int(e))}] {text}", cue))
	return out


def _frame_rms(pcm: np.ndarray, sample_rate: int, t0: float, t1: float, frame_ms: float = 20.0) -> Tuple[np.ndarray, int, int, int]:
	"""Return (rms_frames, frame_samples, abs_sample_start, abs_sample_end)."""
	a = max(0, int(t0 * sample_rate))
	b = min(int(pcm.shape[0]), max(a + 1, int(t1 * sample_rate)))
	chunk = np.asarray(pcm[a:b], dtype=np.float32).reshape(-1)
	frame = max(1, int(sample_rate * frame_ms / 1000.0))
	n = chunk.size // frame
	if n < 1:
		return np.zeros(0, dtype=np.float32), frame, a, b
	frames = chunk[: n * frame].reshape(n, frame)
	rms = np.sqrt(np.mean(np.square(frames), axis=1) + 1e-12)
	return rms.astype(np.float32), frame, a, b


def _speech_thr(rms: np.ndarray) -> float:
	"""Threshold robust to early noise spikes (peak-relative alone sticks to t=0)."""
	if rms.size == 0:
		return 0.02
	# Noise from quieter half of frames; require sustained speech above it.
	quiet = float(np.percentile(rms, 40))
	peak = float(np.max(rms))
	return float(max(0.02, quiet * 2.8, min(peak * 0.18, quiet * 6.0 + 0.01)))


def _sustained_runs(rms: np.ndarray, thr: float, need: int) -> List[Tuple[int, int]]:
	"""All contiguous runs of frames above thr with length ≥ need."""
	above = rms >= thr
	runs: List[Tuple[int, int]] = []
	i = 0
	n = int(above.size)
	while i < n:
		if not bool(above[i]):
			i += 1
			continue
		j = i
		while j < n and bool(above[j]):
			j += 1
		if (j - i) >= need:
			runs.append((i, j))
		i = j
	return runs


def _sustained_span(rms: np.ndarray, thr: float, need: int) -> Tuple[int, int]:
	"""Best speech window: longest / loudest run (ignore brief early noise spikes)."""
	runs = _sustained_runs(rms, thr, need)
	if not runs:
		active = np.where(rms >= thr)[0]
		if active.size == 0:
			return -1, -1
		return int(active[0]), int(active[-1]) + 1
	# Drop early isolated noise (e.g. clack @11s) — keep the late speech cluster.
	# Window = from earliest run in the last ~12s of activity through the last run.
	last_end = max(b for _a, b in runs)
	cluster_from = last_end - int(12.0 / 0.02)  # ~12s lookback at 20ms frames
	cluster = [rj for rj in runs if rj[1] >= cluster_from]
	if not cluster:
		cluster = [max(runs, key=lambda rj: (rj[1] - rj[0]) * float(np.mean(rms[rj[0] : rj[1]]) + 1e-6))]
	i0 = min(a for a, _b in cluster)
	i1 = max(b for _a, b in cluster)
	return int(i0), int(i1)


def _tighten_bounds(
	pcm: np.ndarray,
	sample_rate: int,
	t0: float,
	t1: float,
	*,
	frame_ms: float = 20.0,
	pad_sec: float = 0.15,
	sustain_ms: float = 120.0,
) -> Tuple[float, float]:
	"""Shrink [t0,t1] to sustained speech energy (fixes VAD pads like 00:00→speech@41s)."""
	if pcm.size < 16 or sample_rate < 1 or t1 <= t0:
		return t0, t1
	rms, frame, a, _b = _frame_rms(pcm, sample_rate, t0, t1, frame_ms=frame_ms)
	if rms.size < 3:
		return t0, t1
	thr = _speech_thr(rms)
	need = max(2, int(round(sustain_ms / frame_ms)))
	i0, i1 = _sustained_span(rms, thr, need)
	if i0 < 0:
		return t0, t1
	nt0 = max(t0, (a + i0 * frame) / sample_rate - pad_sec)
	nt1 = min(t1, (a + i1 * frame) / sample_rate + pad_sec)
	if nt1 - nt0 < 0.2:
		return t0, t1
	return max(0.0, nt0), max(nt0 + 0.2, nt1)


def _active_speech_intervals(
	pcm: np.ndarray,
	sample_rate: int,
	t0: float,
	t1: float,
) -> List[Tuple[float, float]]:
	"""Energy-active sub-spans inside an ASR cue (for gap-fill coverage)."""
	rms, frame, a, _b = _frame_rms(pcm, sample_rate, t0, t1)
	if rms.size < 2:
		return [(t0, t1)]
	thr = _speech_thr(rms)
	need = max(2, int(round(100.0 / 20.0)))
	above = rms >= thr
	spans: List[Tuple[float, float]] = []
	i = 0
	while i < above.size:
		if not bool(above[i]):
			i += 1
			continue
		j = i
		while j < above.size and bool(above[j]):
			j += 1
		if (j - i) >= need:
			s0 = (a + i * frame) / sample_rate
			s1 = (a + j * frame) / sample_rate
			spans.append((max(t0, s0), min(t1, s1)))
		i = j
	return spans if spans else [(t0, t1)]


def _tighten_asr_segments(
	pcm: np.ndarray,
	sample_rate: int,
	segs: List[Dict[str, Any]],
) -> List[Dict[str, Any]]:
	"""Prefer word timestamps when present; else energy trim inside each ASR cue."""
	out: List[Dict[str, Any]] = []
	for seg in segs:
		t0 = float(seg["t0"])
		t1 = float(seg["t1"])
		words = seg.get("words") if isinstance(seg.get("words"), list) else []
		spoken = [w for w in words if str(w.get("text") or "").strip()]
		if spoken:
			wt0 = min(float(w["t0"]) for w in spoken)
			wt1 = max(float(w["t1"]) for w in spoken)
			# Keep a little pad but never stretch back to file start silence.
			t0 = max(t0, wt0 - 0.05)
			t1 = min(t1, wt1 + 0.08)
		else:
			t0, t1 = _tighten_bounds(pcm, sample_rate, t0, t1)
		row = dict(seg)
		row["t0"] = t0
		row["t1"] = max(t0 + 0.15, t1)
		out.append(row)
	return out


def _overlap_sec(a0: float, a1: float, b0: float, b1: float) -> float:
	return max(0.0, min(a1, b1) - max(a0, b0))


def _norm_cue_text(text: str) -> str:
	t = re.sub(r"[^\w\s]+", " ", (text or "").lower(), flags=re.UNICODE)
	return re.sub(r"\s+", " ", t).strip()


def _token_jaccard(a: str, b: str) -> float:
	ta = set(_norm_cue_text(a).split())
	tb = set(_norm_cue_text(b).split())
	if not ta or not tb:
		return 0.0
	inter = len(ta & tb)
	union = len(ta | tb)
	return float(inter) / float(union) if union else 0.0


def _token_coverage(needle: str, haystack: str) -> float:
	"""Fraction of needle tokens that appear in haystack (order-agnostic)."""
	nt = set(_norm_cue_text(needle).split())
	ht = set(_norm_cue_text(haystack).split())
	if not nt:
		return 0.0
	return float(len(nt & ht)) / float(len(nt))


def _cue_text_quality(text: str, seg: Optional[Dict[str, Any]] = None) -> int:
	"""Prefer punctuated cabin-style lines over lowercase run-on mush."""
	t = (text or "").strip()
	if not t:
		return -5
	q = 0
	if re.search(r"[?!.…]", t):
		q += 2
	if re.search(r"[А-ЯA-Z]", t):
		q += 1
	if re.search(r"[,:;]", t):
		q += 1
	# Run-on blob: long, mostly lowercase, little punctuation.
	if len(t) >= 36 and t[:1].islower() and not re.search(r"[?!.]", t):
		q -= 3
	elif len(t) >= 48 and not re.search(r"[?!.]", t):
		q -= 2
	if seg and seg.get("gap_fill"):
		q -= 1
	return q


def _seg_prefer_key(seg: Dict[str, Any]) -> Tuple[int, int, int, float]:
	"""Higher is better: primary ASR > text quality > length > span.

	Quality before length so a short punctuated line beats a run-on echo that
	glues the same words into one mush cue.
	"""
	primary = 0 if seg.get("gap_fill") else 1
	text = str(seg.get("text") or "").strip()
	q = _cue_text_quality(text, seg)
	dur = max(0.0, float(seg.get("t1") or 0) - float(seg.get("t0") or 0))
	return (primary, q, len(text), dur)


def _dedupe_asr_segments(
	segs: List[Dict[str, Any]],
	*,
	overlap_ratio: float = 0.40,
	min_overlap_sec: float = 0.45,
	sim_thr: float = 0.38,
	near_gap_sec: float = 3.0,
	near_sim_thr: float = 0.42,
	contain_thr: float = 0.65,
	contain_gap_sec: float = 10.0,
	contain_min_tokens: int = 4,
) -> List[Dict[str, Any]]:
	"""Drop near-duplicate cues from gap_fill vs full-file ASR.

	Handles heavy time overlap, adjacent echoes, and *containment* echoes
	(run-on mush that restates what a later punctuated cue already says).
	"""
	if len(segs) < 2:
		return segs
	ordered = sorted(
		segs,
		key=lambda s: (float(s["t0"]), float(s["t1"]), 0 if s.get("gap_fill") else 1),
	)
	keep: List[Dict[str, Any]] = []
	for seg in ordered:
		t0 = float(seg["t0"])
		t1 = float(seg["t1"])
		text = str(seg.get("text") or "").strip()
		if not text or t1 <= t0:
			continue
		dur = t1 - t0
		toks = set(_norm_cue_text(text).split())
		drop = False
		replace_at = -1
		for i, prev in enumerate(keep):
			p0 = float(prev["t0"])
			p1 = float(prev["t1"])
			prev_text = str(prev.get("text") or "")
			prev_toks = set(_norm_cue_text(prev_text).split())
			ov = _overlap_sec(t0, t1, p0, p1)
			pdur = max(0.05, p1 - p0)
			sim = _token_jaccard(text, prev_text)
			cov_sp = _token_coverage(text, prev_text)
			cov_ps = _token_coverage(prev_text, text)
			# Gap between intervals (0 if overlap) — adjacent echoes often have gap≈1s.
			gap = 0.0 if ov > 0 else (t0 - p1 if t0 >= p1 else p0 - t1)
			near = gap <= near_gap_sec
			near_contain = gap <= contain_gap_sec
			contained = ov >= 0.85 * min(dur, pdur) if ov > 0 else False
			ratio = (ov / min(dur, pdur)) if ov > 0 else 0.0
			same_utt = False
			if ov > 0 and (ratio >= overlap_ratio or ov >= min_overlap_sec or contained):
				same_utt = sim >= sim_thr or (contained and sim >= 0.25)
			elif near and sim >= near_sim_thr:
				same_utt = True
			elif (
				near_contain
				and min(len(toks), len(prev_toks)) >= contain_min_tokens
				and max(cov_sp, cov_ps) >= contain_thr
			):
				# One cue's tokens mostly sit inside the other (split vs glued /
				# alternate ASR hypotheses for the same utterance).
				same_utt = True
			# Gap-fill fully (or almost) inside a primary window.
			if seg.get("gap_fill") and not prev.get("gap_fill") and ov >= 0.75 * dur:
				same_utt = True
			if not same_utt:
				continue
			if _seg_prefer_key(seg) > _seg_prefer_key(prev):
				replace_at = i
			else:
				drop = True
			break
		if drop:
			continue
		if replace_at >= 0:
			keep[replace_at] = seg
		else:
			keep.append(seg)
	keep.sort(key=lambda s: (float(s["t0"]), float(s["t1"])))
	return keep


def _dedupe_coverage_blobs(
	segs: List[Dict[str, Any]],
	*,
	window_before: float = 2.0,
	window_after: float = 12.0,
	cover_thr: float = 0.70,
	min_tokens: int = 5,
) -> List[Dict[str, Any]]:
	"""Drop a run-on cue when nearby *better* cues jointly cover its tokens.

	Classic case: full-file ASR emits one lowercase mush line, then gap_fill /
	a later pass emits the same speech as two punctuated cues — pairwise jaccard
	against each half is too low to catch, but union coverage is high.
	"""
	if len(segs) < 2:
		return segs
	ordered = sorted(
		segs,
		key=lambda s: (float(s["t0"]), float(s["t1"])),
	)
	drop: set = set()
	for i, seg in enumerate(ordered):
		text = str(seg.get("text") or "").strip()
		if not text:
			drop.add(i)
			continue
		toks = set(_norm_cue_text(text).split())
		if len(toks) < min_tokens:
			continue
		qi = _cue_text_quality(text, seg)
		t0 = float(seg["t0"])
		t1 = float(seg["t1"])
		better_bits: List[str] = []
		for j, other in enumerate(ordered):
			if i == j or j in drop:
				continue
			o0 = float(other["t0"])
			o1 = float(other["t1"])
			if o1 < t0 - window_before or o0 > t1 + window_after:
				continue
			ot = str(other.get("text") or "").strip()
			if not ot:
				continue
			qj = _cue_text_quality(ot, other)
			# Count neighbors that are at least as good; blobs also accept any neighbor.
			if qj >= qi or qi <= 0:
				better_bits.append(ot)
		if not better_bits:
			continue
		cov = _token_coverage(text, " ".join(better_bits))
		if cov < cover_thr:
			continue
		# Only drop when this cue is a blob, or several better neighbors cover it.
		if qi <= 0 or (qi <= 2 and len(better_bits) >= 2):
			drop.add(i)
	return [s for i, s in enumerate(ordered) if i not in drop]


def _gap_fill_turns(
	wav: Path,
	turns: Sequence[Dict[str, Any]],
	asr_segs: List[Dict[str, Any]],
	*,
	cli: Path,
	model: Path,
	language: str,
	threads: int,
	asr_cfg: Dict[str, Any],
	pcm: Optional[np.ndarray] = None,
	sample_rate: int = 16000,
	min_turn_sec: float = 0.7,
	min_cover: float = 0.35,
	server_url: str = "",
	form_params: Optional[Dict[str, str]] = None,
	parallel: int = 4,
) -> List[Dict[str, Any]]:
	"""Re-ASR Nemotron turns that the full-file / cabin pass barely covered.

	Coverage uses energy-active parts of ASR cues — a padded ``00:00→00:48`` cue must
	not mark the quiet 38s question as already transcribed.

	Prefers hot cabin ``whisper-server`` for clips (fast); falls back to whisper-cli.
	"""
	active_spans: List[Tuple[float, float]] = []
	for s in asr_segs:
		st0, st1 = float(s["t0"]), float(s["t1"])
		if pcm is not None and pcm.size > 0:
			active_spans.extend(_active_speech_intervals(pcm, sample_rate, st0, st1))
		else:
			active_spans.append((st0, st1))

	todo: List[Tuple[float, float]] = []
	for turn in turns:
		t0 = float(turn["t0"])
		t1 = float(turn["t1"])
		dur = t1 - t0
		if dur < min_turn_sec:
			continue
		covered = sum(_overlap_sec(t0, t1, a0, a1) for a0, a1 in active_spans)
		if covered / dur >= min_cover:
			continue
		todo.append((t0, t1))
	if not todo:
		return asr_segs

	srv = (server_url or "").strip()
	params = dict(form_params or {})
	timeout = float(asr_cfg.get("inference_timeout_sec") or 1800)

	def _one(span: Tuple[float, float]) -> List[Dict[str, Any]]:
		t0, t1 = span
		clip = wav.with_name(f"gap_{int(t0 * 1000):07d}_{int(t1 * 1000):07d}.wav")
		out: List[Dict[str, Any]] = []
		try:
			_cut_wav(wav, clip, t0, t1)
			cues: List[Dict[str, Any]] = []
			if srv:
				try:
					cues = _whisper_server_srt(
						clip,
						server_url=srv,
						form_params=params,
						timeout_sec=min(timeout, 120.0),
						abs_t0=t0,
					)
				except Exception:
					cues = []
			if not cues:
				cues = _whisper_turn(
					cli,
					model,
					clip,
					language=language,
					threads=threads,
					abs_t0=t0,
					asr_cfg=asr_cfg,
				)
			for c in cues:
				text = str(c.get("text") or "").strip()
				if not text:
					continue
				out.append(
					{
						"t0": float(c["t0"]),
						"t1": float(c["t1"]),
						"text": text,
						"words": [],
						"gap_fill": True,
					}
				)
		except Exception:
			return []
		finally:
			try:
				clip.unlink()
			except OSError:
				pass
		return out

	extra: List[Dict[str, Any]] = []
	workers = max(1, min(int(parallel or 1), len(todo)))
	if workers <= 1 or len(todo) == 1:
		for span in todo:
			extra.extend(_one(span))
	else:
		from concurrent.futures import ThreadPoolExecutor, as_completed

		with ThreadPoolExecutor(max_workers=workers) as pool:
			futs = [pool.submit(_one, span) for span in todo]
			for fut in as_completed(futs):
				try:
					extra.extend(fut.result())
				except Exception:
					continue
	if not extra:
		return asr_segs
	merged = list(asr_segs) + extra
	merged.sort(key=lambda s: (float(s["t0"]), float(s["t1"])))
	return merged


def _best_profile(
	vec: np.ndarray,
	profiles: Sequence[Dict[str, Any]],
) -> Tuple[int, float, float]:
	best_admin = 0
	best_sim = -1.0
	second = -1.0
	v = np.asarray(vec, dtype=np.float32).reshape(-1)
	n = float(np.linalg.norm(v))
	if n > 1e-8:
		v = v / n
	for p in profiles:
		admin = int(p.get("id_admin") or p.get("ID_ADMIN") or 0)
		emb = p.get("embedding") or p.get("EMBEDDING") or []
		if admin < 1 or not isinstance(emb, list) or len(emb) < 32:
			continue
		c = np.asarray(emb, dtype=np.float32).reshape(-1)
		dim = min(len(v), len(c))
		if dim < 32:
			continue
		sim = float(np.dot(v[:dim], c[:dim]))
		if sim > best_sim:
			second = best_sim
			best_sim = sim
			best_admin = admin
		elif sim > second:
			second = sim
	return best_admin, best_sim, second


def _embed_window(wav: np.ndarray, t0: float, t1: float, onnx: Path, sample_rate: int = 16000) -> np.ndarray:
	if str(DIARIZE_ROOT) not in sys.path:
		sys.path.insert(0, str(DIARIZE_ROOT))
	from diarize.embedder import WeSpeakerEmbedder  # type: ignore

	emb = WeSpeakerEmbedder(onnx_path=onnx, sample_rate=sample_rate)
	a = max(0.0, float(t0))
	b = max(a + 0.3, float(t1))
	need = 1.5
	if (b - a) < need:
		pad = (need - (b - a)) * 0.5
		a = max(0.0, a - pad)
		b = a + need
	vecs = emb.embed_windows(wav, [(a, b)])
	v = np.asarray(vecs[0], dtype=np.float32).reshape(-1)
	n = float(np.linalg.norm(v))
	if n > 1e-8:
		v = v / n
	return v


def run_pipeline(
	audio_path: Path,
	cfg: Dict[str, Any],
	*,
	profiles: Optional[Sequence[Dict[str, Any]]] = None,
	work_dir: Optional[Path] = None,
) -> AsrDiaResult:
	audio_path = Path(audio_path)
	if not audio_path.is_file():
		return AsrDiaResult(ok=False, error="audio_missing")

	dia = cfg.get("dia") if isinstance(cfg.get("dia"), dict) else {}
	asr = cfg.get("asr") if isinstance(cfg.get("asr"), dict) else {}
	match = cfg.get("match") if isinstance(cfg.get("match"), dict) else {}
	min_turn = float(dia.get("min_turn_sec") or 0.8)
	merge_gap = float(dia.get("merge_gap_sec") or 0.3)
	thr = float(match.get("cue") or 0.50)
	margin = float(match.get("margin") or 0.05)
	min_samples = int(match.get("min_sample_count") or 3)
	min_talk = float(match.get("min_talk_sec") or 15.0)
	model_id = str(asr.get("model_id") or "large-v3")
	asr_mode = str(asr.get("mode") or "words_align").strip().lower()
	reuse_text = str(
		asr.get("reuse_transcription") or asr.get("transcription") or ""
	).strip()
	if asr_mode in ("reuse", "cabin_reuse", "cabin"):
		asr_mode = "reuse"
	elif asr_mode in ("reuse_gap", "reuse-gap", "cabin_gap"):
		asr_mode = "reuse_gap"
	elif reuse_text and bool(asr.get("reuse_cabin")):
		asr_mode = "reuse_gap" if bool(asr.get("gap_fill", True)) else "reuse"
	if asr_mode not in ("words_align", "per_turn", "reuse", "reuse_gap"):
		asr_mode = "words_align"
	models = asr.get("models") if isinstance(asr.get("models"), dict) else {}
	model_path = Path(_expand(str(models.get(model_id) or models.get("large-v3") or "")))
	cli = Path(_expand(str(asr.get("whisper_cli") or "")))
	lang = str(asr.get("language") or "ru")
	threads = int(asr.get("threads") or 4)
	gap_split = float(asr.get("gap_split_sec") or 0.75)
	max_cue = float(asr.get("max_cue_sec") or 14.0)
	onnx = Path(_expand(str(match.get("wespeaker_onnx") or "")))
	if not onnx.is_file():
		cand = DIARIZE_ROOT / "models" / "voxceleb_CAM++_LM.onnx"
		if cand.is_file():
			onnx = cand

	tmpdir = Path(tempfile.mkdtemp(prefix="asr_dia_")) if work_dir is None else Path(work_dir)
	tmpdir.mkdir(parents=True, exist_ok=True)
	wav = tmpdir / "audio.wav"
	t_phases: Dict[str, float] = {}
	try:
		_ffmpeg_wav(audio_path, wav)
		t_dia0 = time.time()
		turns = _speech_diarize(wav, cfg)
		turns = _merge_turns(turns, min_turn_sec=min_turn, merge_gap_sec=merge_gap)
		t_phases["dia_sec"] = round(time.time() - t_dia0, 2)
		profile_list = [p for p in (profiles or []) if isinstance(p, dict)]

		pcm, _dur = _load_mono_16k(wav)
		line_no = 0
		transcription_parts: List[str] = []
		cue_speakers: List[Dict[str, Any]] = []
		segments: List[Dict[str, Any]] = []
		spk_vecs: Dict[str, List[np.ndarray]] = {}
		spk_talk: Dict[str, float] = {}
		spk_admin: Dict[str, Tuple[int, float]] = {}
		word_count = 0

		assembled: List[Dict[str, Any]] = []
		asr_backend = ""
		t_asr0 = time.time()
		# Shared whisper-server URL for full-file + gap_fill clips.
		use_server = bool(asr.get("use_whisper_server", True))
		server_url = ""
		if use_server:
			server_url = str(asr.get("whisper_server") or asr.get("whisper_server_url") or "").strip()
			if not server_url:
				server_url = "http://127.0.0.1:17871/inference"
		form = asr.get("form_params") if isinstance(asr.get("form_params"), dict) else {}
		form_params = {str(k): str(v) for k, v in form.items()} if form else {
			"language": lang,
			"task": "transcribe",
			"response_format": "srt",
			"temperature": "0.0",
			"temperature_inc": "0.0",
		}

		if asr_mode == "reuse":
			# Skip Whisper: map existing cabin cues onto Nemotron turns for speaker ID.
			if not reuse_text:
				return AsrDiaResult(
					ok=False,
					error="reuse_transcription_empty",
					meta={"engine": "asr_dia", "asr_mode": "reuse"},
				)
			cabin_segs = _parse_cabin_transcription(reuse_text)
			if not cabin_segs:
				return AsrDiaResult(
					ok=False,
					error="reuse_transcription_unparsed",
					meta={"engine": "asr_dia", "asr_mode": "reuse"},
				)
			asr_backend = "cabin_reuse"
			for seg in cabin_segs:
				text = str(seg.get("text") or "").strip()
				if not text:
					continue
				t0 = float(seg["t0"])
				t1 = float(seg["t1"])
				spk = _assign_speaker(0.5 * (t0 + t1), turns) or "S0"
				line_no += 1
				# Keep cabin line text/clocks verbatim (no re-ASR, no gap_fill rewrite).
				cabin_line = str(seg.get("cabin_line") or "").strip()
				transcription_parts.append(cabin_line if cabin_line else _format_cue(t0, t1, text))
				cue_speakers.append({"line": line_no, "speaker": spk, "confidence": 0.0})
			t_phases["asr_sec"] = round(time.time() - t_asr0, 2)
		elif asr_mode in ("words_align", "reuse_gap"):
			asr_segs: List[Dict[str, Any]] = []
			word_units: List[Dict[str, Any]] = []
			if asr_mode == "reuse_gap":
				if not reuse_text:
					return AsrDiaResult(
						ok=False,
						error="reuse_transcription_empty",
						meta={"engine": "asr_dia", "asr_mode": "reuse_gap"},
					)
				cabin_segs = _parse_cabin_transcription(reuse_text)
				if not cabin_segs:
					return AsrDiaResult(
						ok=False,
						error="reuse_transcription_unparsed",
						meta={"engine": "asr_dia", "asr_mode": "reuse_gap"},
					)
				asr_segs = [
					{"t0": float(s["t0"]), "t1": float(s["t1"]), "text": s["text"], "words": []}
					for s in cabin_segs
				]
				asr_backend = "cabin_reuse"
			else:
				# Prefer cabin whisper-server (same process flags as production ASR).
				# use_whisper_server=false forces CLI/model_id (needed for podlodka A/B —
				# otherwise a leftover whisper_server URL always wins and ignores model_id).
				if server_url:
					try:
						asr_segs = _whisper_server_srt(
							wav,
							server_url=server_url,
							form_params=form_params,
							timeout_sec=float(asr.get("inference_timeout_sec") or 1800),
						)
						asr_backend = "whisper_server"
					except Exception:
						asr_segs = []
				if not asr_segs:
					ojf = _whisper_full_ojf(
						cli,
						model_path,
						wav,
						language=lang,
						threads=threads,
						out_prefix=tmpdir / "full",
						asr_cfg=asr,
					)
					asr_segs, word_units = _parse_whisper_ojf_units(ojf)
					asr_backend = "whisper_cli_ojf"
			# Drop leading/trailing silence inside ASR cues (e.g. 00:00 pad before speech@41s).
			asr_segs = _tighten_asr_segments(pcm, 16000, asr_segs)
			gap_before = len(asr_segs)
			gap_added = 0
			# reuse_gap always fills; words_align respects asr.gap_fill (default on).
			do_gap = True if asr_mode == "reuse_gap" else bool(asr.get("gap_fill", True))
			if do_gap:
				asr_segs = _gap_fill_turns(
					wav,
					turns,
					asr_segs,
					cli=cli,
					model=model_path,
					language=lang,
					threads=threads,
					asr_cfg=asr,
					pcm=pcm,
					sample_rate=16000,
					min_turn_sec=float(asr.get("gap_fill_min_turn_sec") or 0.7),
					min_cover=float(asr.get("gap_fill_min_cover") or 0.35),
					server_url=server_url,
					form_params=form_params,
					parallel=int(asr.get("gap_fill_parallel") or 4),
				)
				gap_added = max(0, len(asr_segs) - gap_before)
				if gap_added > 0:
					asr_backend = (asr_backend or "asr") + "+gap_fill"
					if server_url:
						asr_backend += "_server"
					asr_segs = _tighten_asr_segments(pcm, 16000, asr_segs)
			# Collapse server↔gap_fill echoes (overlapping times + similar text).
			before_dedupe = len(asr_segs)
			asr_segs = _dedupe_asr_segments(asr_segs)
			if len(asr_segs) < before_dedupe:
				asr_backend = (asr_backend or "asr") + "+dedupe"
			word_count = len(word_units)
			assembled = _assemble_cues_from_asr(
				asr_segs,
				turns,
				gap_split_sec=gap_split,
				max_cue_sec=max_cue,
			)
			# Dedupe again after speaker/gap splits (keep speaker on winner).
			assembled = _dedupe_asr_segments(
				[
					{
						"t0": c["t0"],
						"t1": c["t1"],
						"text": c["text"],
						"speaker": c.get("speaker"),
						"gap_fill": c.get("gap_fill"),
					}
					for c in assembled
				]
			)
			# Drop run-on mush covered by several nearby punctuated cues.
			assembled = _dedupe_coverage_blobs(assembled)
			final_cues = []
			for cue in assembled:
				text = str(cue.get("text") or "").strip()
				if not text:
					continue
				ct0 = float(cue["t0"])
				ct1 = float(cue["t1"])
				# Final energy tighten after speaker assemble (bounds may have widened).
				ct0, ct1 = _tighten_bounds(pcm, 16000, ct0, ct1)
				final_cues.append(
					{
						"t0": ct0,
						"t1": ct1,
						"text": text,
						"speaker": str(cue.get("speaker") or "S0"),
						"gap_fill": cue.get("gap_fill"),
					}
				)
			final_cues = _dedupe_coverage_blobs(final_cues)
			final_cues = _sequentialize_cues(final_cues)
			for line, cue in _format_cues_sequential(final_cues):
				line_no += 1
				transcription_parts.append(line)
				cue_speakers.append(
					{"line": line_no, "speaker": str(cue.get("speaker") or "S0"), "confidence": 0.0}
				)
			t_phases["asr_sec"] = round(time.time() - t_asr0, 2)
			t_phases["gap_fill_added"] = gap_added
		else:
			final_cues = []
			for i, turn in enumerate(turns):
				spk = str(turn["speaker"])
				t0 = float(turn["t0"])
				t1 = float(turn["t1"])
				clip = tmpdir / f"turn_{i:04d}.wav"
				_cut_wav(wav, clip, t0, t1)
				cues = _whisper_turn(cli, model_path, clip, language=lang, threads=threads, abs_t0=t0)
				for cue in cues:
					text = str(cue.get("text") or "").strip()
					if not text:
						continue
					final_cues.append(
						{
							"t0": float(cue["t0"]),
							"t1": float(cue["t1"]),
							"text": text,
							"speaker": spk,
						}
					)
			final_cues = _sequentialize_cues(final_cues)
			for line, cue in _format_cues_sequential(final_cues):
				line_no += 1
				transcription_parts.append(line)
				cue_speakers.append(
					{"line": line_no, "speaker": str(cue.get("speaker") or "S0"), "confidence": 0.0}
				)
			t_phases["asr_sec"] = round(time.time() - t_asr0, 2)

		t_id0 = time.time()
		for turn in turns:
			spk = str(turn["speaker"])
			t0 = float(turn["t0"])
			t1 = float(turn["t1"])
			segments.append({"t0": round(t0, 3), "t1": round(t1, 3), "speaker": spk})
			spk_talk[spk] = spk_talk.get(spk, 0.0) + max(0.0, t1 - t0)
			if onnx.is_file():
				try:
					vec = _embed_window(pcm, t0, t1, onnx)
					spk_vecs.setdefault(spk, []).append(vec)
				except Exception:
					pass

		# Drop under-enrolled profiles (weak centroids steal turns — e.g. 1×5s sample).
		usable_profiles: List[Dict[str, Any]] = []
		for p in profile_list:
			sc = int(p.get("sample_count") or len(p.get("samples") or []) or 0)
			talk = float(p.get("talk_sec") or 0)
			if "sample_count" not in p and "talk_sec" not in p and "samples" not in p:
				usable_profiles.append(p)
				continue
			if sc >= min_samples or talk >= min_talk:
				usable_profiles.append(p)

		speakers: List[Dict[str, Any]] = []
		for spk, vecs in spk_vecs.items():
			cent = np.mean(np.stack(vecs, axis=0), axis=0)
			n = float(np.linalg.norm(cent))
			if n > 1e-8:
				cent = cent / n
			admin, sim, second = _best_profile(cent, usable_profiles)
			role = "other"
			id_admin = 0
			conf = float(sim) if sim > 0 else 0.0
			ok = admin > 0 and sim >= thr and (sim - second) >= margin
			if ok:
				role = "staff"
				id_admin = admin
				spk_admin[spk] = (admin, sim)
			speakers.append(
				{
					"id": spk if id_admin < 1 else f"A{id_admin}",
					"role": role,
					"id_admin": id_admin,
					"confidence": round(float(conf), 4),
					"talk_sec": round(float(spk_talk.get(spk, 0.0)), 3),
					"embedding": [round(float(x), 6) for x in cent.astype(np.float32).tolist()],
					"local_id": spk,
					"match_best": round(float(sim), 4),
					"match_second": round(float(second), 4),
					"match_rejected": (not ok) and admin > 0,
				}
			)

		local_to_final = {s.get("local_id") or s["id"]: s["id"] for s in speakers}
		for c in cue_speakers:
			loc = str(c.get("speaker") or "")
			c["speaker"] = local_to_final.get(loc, loc)
			if loc in spk_admin:
				c["confidence"] = round(float(spk_admin[loc][1]), 4)
		for seg in segments:
			loc = str(seg.get("speaker") or "")
			seg["speaker"] = local_to_final.get(loc, loc)

		matched_lines = sum(1 for c in cue_speakers if str(c.get("speaker") or "").startswith("A"))
		t_phases["id_sec"] = round(time.time() - t_id0, 2)
		meta_out: Dict[str, Any] = {
			"engine": "asr_dia",
			"dia_engine": str(dia.get("engine") or "nemotron3"),
			"asr_model_id": (
				"cabin" if asr_mode in ("reuse", "reuse_gap") else model_id
			),
			"asr_mode": asr_mode,
			"asr_backend": asr_backend
			or (
				"per_turn"
				if asr_mode == "per_turn"
				else ("cabin_reuse" if asr_mode in ("reuse", "reuse_gap") else "")
			),
			"words": word_count,
			"assembled_cues": (
				len(assembled)
				if asr_mode in ("words_align", "reuse_gap")
				else len(cue_speakers)
			),
			"turns": len(turns),
			"cues": len(cue_speakers),
			"matched_cues": matched_lines,
			"matched_pct": round(100.0 * matched_lines / max(1, len(cue_speakers)), 1),
		}
		meta_out.update(t_phases)
		return AsrDiaResult(
			ok=True,
			transcription="\n".join(transcription_parts),
			speakers=speakers,
			segments=segments,
			cue_speakers=cue_speakers,
			meta=meta_out,
		)
	except Exception as e:
		return AsrDiaResult(ok=False, error=str(e)[:800], meta={"engine": "asr_dia", "asr_mode": asr_mode})
	finally:
		if work_dir is None:
			shutil.rmtree(tmpdir, ignore_errors=True)
