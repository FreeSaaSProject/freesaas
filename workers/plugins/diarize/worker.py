#!/usr/bin/env python3
"""FreeSaaS voice vectorizer: CAM++ embed for manual samples (file diarize retired)."""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import time
import traceback
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

import numpy as np
import requests

PLUGIN_ROOT = Path(__file__).resolve().parent
_REPO_ROOT = PLUGIN_ROOT.parent.parent.parent
if str(PLUGIN_ROOT) not in sys.path:
	sys.path.insert(0, str(PLUGIN_ROOT))
if str(_REPO_ROOT) not in sys.path:
	sys.path.insert(0, str(_REPO_ROOT))

from diarize.audio_io import load_mono_16k  # noqa: E402
from diarize.embedder import WeSpeakerEmbedder  # noqa: E402
from diarize.engine import config_from_dict, diarize_file  # noqa: E402

try:
	from workers.core.status import StatusWriter
	from workers.core.i18n import t as _i18n_t
except ImportError:  # pragma: no cover
	StatusWriter = None  # type: ignore

	def _i18n_t(key: str, **kwargs: Any) -> str:  # type: ignore
		return key

STATUS_PATH = PLUGIN_ROOT / "logs" / "status.json"
CONFIG_PATH = PLUGIN_ROOT / "configs" / "local.json"
EXAMPLE_PATH = PLUGIN_ROOT / "configs" / "example.json"

_PROFILES_CACHE: Dict[str, Any] = {"ts": 0.0, "ready": False}
_STATUS_WRITER: Any = None


def tl(key: str, **kwargs: Any) -> str:
	return _i18n_t(key, plugin="diarize", **kwargs)


def load_config() -> Dict[str, Any]:
	path = CONFIG_PATH if CONFIG_PATH.is_file() else EXAMPLE_PATH
	data = json.loads(path.read_text(encoding="utf-8"))
	return data if isinstance(data, dict) else {}


def set_activity(phase: str, detail: str = "") -> None:
	if _STATUS_WRITER is None:
		return
	_STATUS_WRITER.set_activity(phase, detail)


def status_update(**fields: Any) -> None:
	if _STATUS_WRITER is None:
		return
	_STATUS_WRITER.update(**fields)


def bump_counter(key: str, delta: int = 1) -> None:
	if _STATUS_WRITER is None:
		return
	cur = _STATUS_WRITER.data.get("counters") if isinstance(_STATUS_WRITER.data.get("counters"), dict) else {}
	n = int(cur.get(key) or 0) + int(delta)
	status_update(counters={key: n})


def idle_sleep(total_sec: int, *, detail: str = "") -> None:
	"""Sleep with heartbeat so dashboard does not look frozen / stale."""
	total = max(1, int(total_sec))
	chunk = 15
	left = total
	while left > 0:
		msg = detail or tl("activity.idle_sleep", sec=left)
		set_activity("idle", msg)
		time.sleep(min(chunk, left))
		left -= chunk


def log(*args: Any) -> None:
	print(*args, flush=True)


def backend_headers(cfg: Dict[str, Any]) -> Dict[str, str]:
	token = str(cfg.get("worker_token") or "").strip()
	h = {"Accept": "application/json"}
	if token:
		h["X-Audio-Token"] = token
	return h


def tls_verify(cfg: Dict[str, Any]) -> Any:
	tls = cfg.get("tls") if isinstance(cfg.get("tls"), dict) else {}
	ca = str(tls.get("ca_bundle") or "").strip()
	if ca:
		return ca
	return bool(tls.get("verify", True))


def diarize_server_state(cfg: Dict[str, Any], *, force: bool = False) -> Dict[str, Any]:
	"""Cached GET diarize-status: FreeSaaS master switch + profiles gate + vectors."""
	now = time.time()
	if not force and now - float(_PROFILES_CACHE["ts"]) < 60.0:
		return {
			"enabled": bool(_PROFILES_CACHE.get("enabled", True)),
			"ready": bool(_PROFILES_CACHE.get("ready", False)),
			"profiles": list(_PROFILES_CACHE.get("profiles") or []),
			"match_cue": float(_PROFILES_CACHE.get("match_cue") or 0.48),
			"match_margin": float(_PROFILES_CACHE.get("match_margin") or 0.0),
		}
	server = str(cfg.get("server") or "").rstrip("/")
	ep = (cfg.get("endpoints") or {}).get("status") or "/audio/asr/diarize-status"
	enabled = True
	ready = False
	profiles: List[Dict[str, Any]] = []
	match_cue = 0.48
	match_margin = 0.0
	try:
		r = requests.get(
			server + ep,
			headers=backend_headers(cfg),
			verify=tls_verify(cfg),
			timeout=60,
		)
		r.raise_for_status()
		data = r.json() if r.text else {}
		if isinstance(data, dict):
			# Missing key → legacy server, treat as enabled.
			enabled = data.get("diarize_enabled", True) is not False
			ready = bool(data.get("profiles_ready"))
			if data.get("requires_profiles") is False:
				ready = True
			if not enabled:
				ready = False
			raw_profiles = data.get("profiles")
			if isinstance(raw_profiles, list):
				profiles = [p for p in raw_profiles if isinstance(p, dict)]
			try:
				match_cue = float(data.get("match_cue") or match_cue)
			except (TypeError, ValueError):
				pass
			try:
				match_margin = float(data.get("match_margin") or match_margin)
			except (TypeError, ValueError):
				pass
	except Exception as e:
		log("diarize status check failed:", e)
		enabled = True
		ready = False
		profiles = []
	_PROFILES_CACHE["ts"] = now
	_PROFILES_CACHE["enabled"] = enabled
	_PROFILES_CACHE["ready"] = ready
	_PROFILES_CACHE["profiles"] = profiles
	_PROFILES_CACHE["match_cue"] = match_cue
	_PROFILES_CACHE["match_margin"] = match_margin
	return {
		"enabled": enabled,
		"ready": ready,
		"profiles": profiles,
		"match_cue": match_cue,
		"match_margin": match_margin,
	}


def profiles_ready(cfg: Dict[str, Any], *, force: bool = False) -> bool:
	return bool(diarize_server_state(cfg, force=force)["ready"])


def diarize_enabled(cfg: Dict[str, Any], *, force: bool = False) -> bool:
	return bool(diarize_server_state(cfg, force=force)["enabled"])


def claim_embed(cfg: Dict[str, Any]) -> Optional[Dict[str, Any]]:
	server = str(cfg.get("server") or "").rstrip("/")
	ep = (cfg.get("endpoints") or {}).get("embed_claim") or "/audio/asr/embed-sample-claim"
	params: Dict[str, Any] = {}
	filt = cfg.get("filter") if isinstance(cfg.get("filter"), dict) else {}
	admin = int(filt.get("admin") or 0)
	if admin > 0:
		params["admin"] = admin
	r = requests.get(
		server + ep,
		params=params or None,
		headers=backend_headers(cfg),
		verify=tls_verify(cfg),
		timeout=60,
	)
	r.raise_for_status()
	text = (r.text or "").strip()
	if not text or text == "None" or text.lower() == "null":
		return None
	if not text.startswith("{"):
		return None
	data = r.json()
	if not isinstance(data, dict) or not data.get("id"):
		return None
	return data


def claim_diarize(cfg: Dict[str, Any]) -> Optional[Dict[str, Any]]:
	server = str(cfg.get("server") or "").rstrip("/")
	ep = (cfg.get("endpoints") or {}).get("claim") or "/audio/asr/diarize-claim"
	url = server + ep
	params: Dict[str, Any] = {}
	filt = cfg.get("filter") if isinstance(cfg.get("filter"), dict) else {}
	day = str(filt.get("day") or "").strip()
	if day:
		params["day"] = day
	admin = int(filt.get("admin") or 0)
	if admin > 0:
		params["admin"] = admin
	lookback = int(filt.get("lookback") or 0)
	if lookback > 0:
		params["lookback"] = lookback
	r = requests.get(
		url,
		params=params or None,
		headers=backend_headers(cfg),
		verify=tls_verify(cfg),
		timeout=60,
	)
	r.raise_for_status()
	text = (r.text or "").strip()
	if not text or text == "None" or text.lower() == "null":
		return None
	if text.startswith("{"):
		data = r.json()
		if not isinstance(data, dict) or not data.get("file_name"):
			return None
		return data
	return {"file_name": text, "transcription": ""}


def download_audio(cfg: Dict[str, Any], file_name: str, dest: Path, source_url: str = "") -> None:
	url = str(source_url or "").strip()
	if not url:
		ftp = str(cfg.get("ftp_server") or "").rstrip("/")
		url = f"{ftp}{file_name}" if file_name.startswith("/") else f"{ftp}/{file_name}"
	with requests.get(url, stream=True, verify=tls_verify(cfg), timeout=300) as r:
		r.raise_for_status()
		with open(dest, "wb") as f:
			for chunk in r.iter_content(chunk_size=1024 * 256):
				if chunk:
					f.write(chunk)


def _ffmpeg_bin() -> str:
	import os
	import shutil

	found = shutil.which("ffmpeg")
	if found:
		return found
	for cand in (
		"/opt/homebrew/bin/ffmpeg",
		"/usr/local/bin/ffmpeg",
		"/opt/local/bin/ffmpeg",
	):
		if Path(cand).is_file():
			return cand
	# last resort: keep name so error message is clear
	return os.environ.get("FFMPEG", "ffmpeg")


def _ffmpeg_slice(src: Path, dest: Path, t0: float, t1: float) -> None:
	"""Cut [t0,t1) seconds. -ss after -i for accurate MP3 seek (not keyframe-approx)."""
	dur = max(0.05, float(t1) - float(t0))
	ff = _ffmpeg_bin()
	cmd = [
		ff,
		"-y",
		"-i",
		str(src),
		"-ss",
		f"{t0:.3f}",
		"-t",
		f"{dur:.3f}",
		"-ac",
		"1",
		"-ar",
		"16000",
		"-f",
		"wav",
		str(dest),
	]
	proc = subprocess.run(cmd, check=False, capture_output=True)
	if proc.returncode != 0 or not dest.is_file() or dest.stat().st_size < 1000:
		err = (proc.stderr or b"")[:400].decode("utf-8", "replace")
		raise RuntimeError(f"ffmpeg slice failed ({ff}): {err}")


def _embedder(cfg: Dict[str, Any]) -> WeSpeakerEmbedder:
	dcfg = config_from_dict(cfg, PLUGIN_ROOT)
	return WeSpeakerEmbedder(
		dcfg.onnx_path,
		sample_rate=dcfg.sample_rate,
		threads=dcfg.threads,
		backend=dcfg.backend,
		mlx_dir=dcfg.mlx_dir,
	)


def post_embed(
	cfg: Dict[str, Any],
	sample_id: int,
	embedding: List[float],
	*,
	build_profile: bool = True,
) -> None:
	server = str(cfg.get("server") or "").rstrip("/")
	ep = (cfg.get("endpoints") or {}).get("embed_save") or "/audio/asr/embed-sample-result"
	body = {
		"sample_id": str(sample_id),
		"embedding": json.dumps(embedding),
		"build_profile": "1" if build_profile else "0",
	}
	r = requests.post(
		server + ep,
		data=body,
		headers=backend_headers(cfg),
		verify=tls_verify(cfg),
		timeout=60,
	)
	r.raise_for_status()
	first = (r.text or "").strip().splitlines()[0] if (r.text or "").strip() else ""
	if first not in ("true", "1", "True"):
		raise RuntimeError(f"embed save failed: {(r.text or '')[:240]}")


def post_speakers(cfg: Dict[str, Any], file_name: str, payload: Dict[str, Any]) -> None:
	server = str(cfg.get("server") or "").rstrip("/")
	ep = (cfg.get("endpoints") or {}).get("save") or "/audio/asr/diarize-result"
	url = server + ep
	body = {
		"file_name": file_name,
		"speakers": json.dumps(payload.get("speakers") or [], ensure_ascii=False),
		"segments": json.dumps(payload.get("segments") or [], ensure_ascii=False),
		"cue_speakers": json.dumps(payload.get("cue_speakers") or [], ensure_ascii=False),
		"dominant_speaker": str(payload.get("dominant_speaker") or ""),
	}
	retries = int(((cfg.get("tls") or {}).get("upload_retries")) or 3)
	sleep_s = float(((cfg.get("tls") or {}).get("upload_retry_sleep_sec")) or 2.0)
	last_err: Optional[BaseException] = None
	for attempt in range(1, retries + 1):
		try:
			r = requests.post(
				url,
				data=body,
				headers=backend_headers(cfg),
				verify=tls_verify(cfg),
				timeout=60,
			)
			if r.status_code < 200 or r.status_code >= 300:
				raise RuntimeError(f"HTTP {r.status_code}: {(r.text or '')[:200]}")
			first = (r.text or "").strip().splitlines()[0] if (r.text or "").strip() else ""
			if first not in ("true", "1", '{"ok":true}', "True"):
				try:
					j = r.json()
					if isinstance(j, dict) and j.get("ok"):
						return
					if j is True:
						return
				except Exception:
					pass
				if first.lower() == "true":
					return
				body_l = (r.text or "").lower()
				if "true" in body_l or '"ok":true' in body_l.replace(" ", ""):
					return
				raise RuntimeError(f"server did not ack: {(r.text or '')[:240]}")
			return
		except Exception as e:
			last_err = e
			log(f"upload attempt {attempt}/{retries} failed:", e)
			if attempt < retries:
				time.sleep(sleep_s)
	raise RuntimeError(f"upload failed: {last_err}")


def process_embed(
	cfg: Dict[str, Any],
	job: Dict[str, Any],
	emb: WeSpeakerEmbedder,
	*,
	push: bool = False,
) -> Optional[Dict[str, Any]]:
	sample_id = int(job.get("sample_id") or job.get("id") or 0)
	file_name = str(job.get("file_name") or job.get("filename") or "")
	t0 = float(job.get("t0") or 0)
	t1 = float(job.get("t1") or 0)
	source_url = str(job.get("source_url") or "")
	if sample_id < 1 or not file_name or t1 <= t0:
		return None
	log(f"embed sample #{sample_id} {Path(file_name).name} {t0:.1f}-{t1:.1f}s")
	set_activity("embed", tl("activity.embed", id=sample_id))
	status_update(ok=True, meta={"last_kind": "embed", "last_sample": sample_id, "file": file_name})
	with tempfile.TemporaryDirectory() as tmp:
		raw = Path(tmp) / Path(file_name).name
		clip = Path(tmp) / "clip.wav"
		set_activity("download", Path(file_name).name)
		download_audio(cfg, file_name, raw, source_url)
		_ffmpeg_slice(raw, clip, t0, t1)
		set_activity("embed", tl("activity.embed", id=sample_id))
		wav, _dur = load_mono_16k(clip, emb.sample_rate)
		vec = emb.embed_waveform(wav)
		if vec.size < 32:
			raise RuntimeError(f"empty embedding for sample {sample_id}")
		embedding = [float(x) for x in vec.tolist()]
		if push:
			log(f"done embed #{sample_id} (push result)")
			status_update(ok=True, meta={"last_kind": "embed", "last_sample": sample_id})
			return {
				"kind": "embed",
				"sample_id": sample_id,
				"embedding": embedding,
				"build_profile": True,
				"file_name": file_name,
			}
		set_activity("upload", tl("activity.upload_embed", id=sample_id))
		post_embed(cfg, sample_id, embedding, build_profile=True)
	log(f"done embed #{sample_id}")
	status_update(ok=True, meta={"last_kind": "embed", "last_sample": sample_id})
	return None


def process_diarize(
	cfg: Dict[str, Any],
	job: Dict[str, Any],
	*,
	push: bool = False,
) -> Optional[Dict[str, Any]]:
	file_name = str(job.get("file_name") or "")
	if not file_name:
		return None
	base = Path(file_name).name
	source_url = str(job.get("source_url") or "")
	log(tl("log.got_file", name=base))
	set_activity("diarize", tl("activity.diarize", name=base))
	status_update(ok=True, meta={"last_kind": "diarize", "file": file_name})
	dcfg = config_from_dict(cfg, PLUGIN_ROOT)
	profiles = job.get("profiles") if isinstance(job.get("profiles"), list) else None
	if profiles is None:
		state = diarize_server_state(cfg, force=True)
		profiles = list(state.get("profiles") or [])
		try:
			dcfg.match_cue = float(state.get("match_cue") or dcfg.match_cue)
		except (TypeError, ValueError):
			pass
		try:
			dcfg.match_margin = float(state.get("match_margin") or dcfg.match_margin)
		except (TypeError, ValueError):
			pass
	else:
		try:
			dcfg.match_cue = float(job.get("match_cue") or dcfg.match_cue)
		except (TypeError, ValueError):
			pass
		try:
			dcfg.match_margin = float(job.get("match_margin") or dcfg.match_margin)
		except (TypeError, ValueError):
			pass
	log(
		f"profiles={len(profiles or [])} match_cue={dcfg.match_cue:.3f} "
		f"match_margin={dcfg.match_margin:.3f}"
	)
	with tempfile.TemporaryDirectory() as tmp:
		local = Path(tmp) / base
		set_activity("download", base)
		download_audio(cfg, file_name, local, source_url)
		set_activity("diarize", tl("activity.diarize", name=base))
		result = diarize_file(
			local,
			dcfg,
			transcription=str(job.get("transcription") or ""),
			profiles=list(profiles or []),
		)
		payload = result.to_payload()
		if push:
			log(
				tl("log.done", name=base, n=len(result.speakers), dom=result.dominant_speaker)
				+ " (push result)"
			)
			status_update(
				ok=True,
				meta={
					"last_kind": "diarize",
					"last_file": file_name,
					"last_speakers": len(result.speakers),
					"last_dominant": result.dominant_speaker,
				},
			)
			return {
				"kind": "file",
				"file_name": file_name,
				"speakers": payload.get("speakers") or [],
				"segments": payload.get("segments") or [],
				"cue_speakers": payload.get("cue_speakers") or [],
				"dominant_speaker": payload.get("dominant_speaker") or "",
			}
		set_activity("upload", tl("activity.upload", name=base))
		post_speakers(cfg, file_name, payload)
		n_cues = len(result.cue_speakers or [])
		log(
			tl("log.done", name=base, n=len(result.speakers), dom=result.dominant_speaker)
			+ f" cues_matched={n_cues}"
		)
		status_update(
			ok=True,
			meta={
				"last_kind": "diarize",
				"last_file": file_name,
				"last_speakers": len(result.speakers),
				"last_dominant": result.dominant_speaker,
			},
		)
	return None


PUSH_STORE: Any = None
PUSH_WAKE: Any = None
PUSH_MAX_ATTEMPTS = 5
PUSH_RETRY_DELAY = 120.0


def start_push_mode(cfg: Dict[str, Any]) -> None:
	global PUSH_STORE, PUSH_WAKE, PUSH_MAX_ATTEMPTS, PUSH_RETRY_DELAY
	import threading

	from push_store import PushStore
	import push_server
	from workers.core.gateway import local_api

	push_cfg = cfg.get("push") if isinstance(cfg.get("push"), dict) else {}
	PUSH_WAKE = threading.Event()
	db_raw = Path(str(push_cfg.get("db") or "var/push/queue.db")).expanduser()
	db_path = db_raw if db_raw.is_absolute() else (PLUGIN_ROOT / db_raw).resolve()
	PUSH_STORE = PushStore(
		db_path,
		capacity=int(push_cfg.get("capacity", 40)),
		max_unacked=int(push_cfg.get("max_unacked", 1000)),
	)
	PUSH_MAX_ATTEMPTS = int(push_cfg.get("max_attempts", 5))
	PUSH_RETRY_DELAY = float(push_cfg.get("retry_delay_sec", 120))
	n = PUSH_STORE.reset_processing()
	if n:
		log(f"push: requeued {n} unfinished job(s)")
	manifest: Dict[str, Any] = {}
	try:
		manifest = json.loads((PLUGIN_ROOT / "plugin.json").read_text(encoding="utf-8"))
	except Exception:
		pass
	api = push_server.PushApi(
		PUSH_STORE,
		worker_name=str(push_cfg.get("worker_name") or "diarize"),
		version=str(manifest.get("version") or ""),
		ready=lambda: True,
		wake=PUSH_WAKE,
		log=log,
	)
	port = int(manifest.get("local_api_port") or 18472)
	local_api.serve(port, api.handle, log=log)
	log(f"push API 127.0.0.1:{port} (gateway /v1/diarize/*)")


def process_push_job(
	cfg: Dict[str, Any], emb: Optional[WeSpeakerEmbedder]
) -> Tuple[Optional[WeSpeakerEmbedder], bool]:
	"""One push job. Returns (embedder, did_work)."""
	global PUSH_STORE
	if PUSH_STORE is None:
		return emb, False
	job = PUSH_STORE.next_job()
	if not job:
		return emb, False
	job_id = str(job.get("job_id") or "")
	kind = str(job.get("kind") or "")
	bump_counter("files_req")
	try:
		if kind != "embed":
			# File WeSpeaker diarize retired — drop non-embed jobs without loading engine.
			log(f"skip non-embed push job kind={kind!r} (vectorizer is embed-only)")
			PUSH_STORE.finish(
				job_id,
				{"kind": kind or "file", "status": "skipped", "error": "file_diarize_retired"},
			)
			bump_counter("files_ok")
			return emb, True
		if emb is None:
			emb = _embedder(cfg)
		result = process_embed(cfg, job, emb, push=True)
		if result is None:
			raise RuntimeError("empty result")
		PUSH_STORE.finish(job_id, result)
		bump_counter("files_ok")
	except Exception as e:
		bump_counter("files_err")
		requeued = PUSH_STORE.retry_later(job_id, str(e), PUSH_RETRY_DELAY, PUSH_MAX_ATTEMPTS)
		log("push job error:", e, "→", "retry" if requeued else "failed")
		if not requeued:
			raise
	return emb, True


def main() -> int:
	global _STATUS_WRITER
	cfg = load_config()
	modes = cfg.get("modes") if isinstance(cfg.get("modes"), dict) else {}
	# Default: push-only (shared hosting). Opt into pull for labs without gateway.
	pull_enabled = bool(modes.get("pull", False))
	push_enabled = bool(modes.get("push", True))
	if StatusWriter is not None:
		_STATUS_WRITER = StatusWriter(STATUS_PATH, "diarize")
		_STATUS_WRITER.update(
			ok=True,
			meta={
				"server": str(cfg.get("server") or ""),
				"mode": str((cfg.get("mode") or "auto")).strip().lower(),
				"pull": pull_enabled,
				"push": push_enabled,
			},
			counters={"files_req": 0, "files_ok": 0, "files_err": 0},
		)
		set_activity("starting", tl("activity.idle_boot"))
	if not str(cfg.get("worker_token") or "").strip() and pull_enabled:
		log("WARNING:", tl("log.token_missing"))
	if push_enabled:
		start_push_mode(cfg)
	if not pull_enabled and not push_enabled:
		raise SystemExit("modes.pull and modes.push are both off")
	idle = int(((cfg.get("loop") or {}).get("sleep_seconds_idle")) or 120)
	run_sleep = int(((cfg.get("loop") or {}).get("sleep_seconds_run")) or 1)
	# Default embed-only — file WeSpeaker diarize is retired (use asr_dia).
	mode = str((cfg.get("mode") or "embed")).strip().lower()
	if mode in ("auto", "diarize"):
		log(f"mode={mode!r} forced to embed (file diarize retired)")
		mode = "embed"
	status_update(meta={"mode": mode, "pull": pull_enabled, "push": push_enabled})
	emb: Optional[WeSpeakerEmbedder] = None
	while True:
		try:
			if push_enabled and PUSH_STORE is not None:
				status_update(meta={"push": PUSH_STORE.counts()})
				had = False
				while True:
					emb, did = process_push_job(cfg, emb)
					if not did:
						break
					had = True
					time.sleep(run_sleep)
				if had:
					continue
				if not pull_enabled:
					set_activity("idle", tl("activity.push_wait"))
					if PUSH_WAKE is not None:
						PUSH_WAKE.wait(timeout=max(5, min(idle, 60)))
						PUSH_WAKE.clear()
					else:
						time.sleep(max(5, min(idle, 60)))
					continue

			if not pull_enabled:
				idle_sleep(idle, detail=tl("activity.push_wait"))
				continue

			# Pull path (labs): embed claims only.
			set_activity("claim", tl("activity.claim_embed"))
			job = claim_embed(cfg)
			if job:
				bump_counter("files_req")
				try:
					if emb is None:
						emb = _embedder(cfg)
					process_embed(cfg, job, emb, push=False)
					bump_counter("files_ok")
				except Exception:
					bump_counter("files_err")
					raise
				time.sleep(run_sleep)
				continue
			idle_sleep(idle, detail=tl("log.no_files_wait", sec=idle))
		except KeyboardInterrupt:
			status_update(ok=False)
			set_activity("idle", "stopped")
			return 0
		except Exception as e:
			log("ERROR:", e)
			traceback.print_exc()
			status_update(ok=False, meta={"last_error": str(e)[:300]})
			set_activity("error", str(e)[:200])
			idle_sleep(max(5, idle // 2), detail=tl("activity.error_backoff"))


if __name__ == "__main__":
	raise SystemExit(main())
