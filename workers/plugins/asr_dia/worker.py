#!/usr/bin/env python3
"""FreeSaaS asr_dia worker: dia-first → ASR per turn → WeSpeaker match (experiment)."""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import time
import traceback
from pathlib import Path
from typing import Any, Dict, Optional, Tuple
from urllib.parse import urlsplit

import requests

PLUGIN_ROOT = Path(__file__).resolve().parent
_REPO_ROOT = PLUGIN_ROOT.parent.parent.parent
if str(PLUGIN_ROOT) not in sys.path:
	sys.path.insert(0, str(PLUGIN_ROOT))
if str(_REPO_ROOT) not in sys.path:
	sys.path.insert(0, str(_REPO_ROOT))

from asr_dia.pipeline import run_pipeline  # noqa: E402

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

_STATUS_WRITER: Any = None
PUSH_STORE: Any = None
PUSH_WAKE: Any = None
PUSH_MAX_ATTEMPTS = 5
PUSH_RETRY_DELAY = 120.0


def tl(key: str, **kwargs: Any) -> str:
	return _i18n_t(key, plugin="asr_dia", **kwargs)


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


def download_audio(cfg: Dict[str, Any], file_name: str, dest: Path, source_url: str = "") -> None:
	url = (source_url or "").strip()
	if not url:
		ftp = str(cfg.get("ftp_server") or cfg.get("server") or "").rstrip("/")
		path = file_name if file_name.startswith("/") else "/" + file_name
		url = ftp + path
	r = requests.get(url, headers=backend_headers(cfg), verify=tls_verify(cfg), timeout=600, stream=True)
	r.raise_for_status()
	dest.parent.mkdir(parents=True, exist_ok=True)
	with dest.open("wb") as f:
		for chunk in r.iter_content(chunk_size=1024 * 256):
			if chunk:
				f.write(chunk)


def fetch_profiles(cfg: Dict[str, Any], record_id: int = 0) -> Tuple[list, float]:
	"""Pull shift-scoped profiles when job payload omitted them. Requires record_id."""
	server = str(cfg.get("server") or "").rstrip("/")
	ep = (cfg.get("endpoints") or {}).get("status") or "/audio/asr/asr-dia-status"
	profiles: list = []
	match_cue = float(((cfg.get("match") or {}).get("cue")) or 0.45)
	if record_id < 1:
		return profiles, match_cue
	try:
		url = server + ep
		sep = "&" if "?" in ep else "?"
		url = f"{url}{sep}record_id={int(record_id)}"
		r = requests.get(url, headers=backend_headers(cfg), verify=tls_verify(cfg), timeout=60)
		r.raise_for_status()
		data = r.json() if r.text else {}
		if isinstance(data, dict):
			raw = data.get("profiles")
			if isinstance(raw, list):
				profiles = [p for p in raw if isinstance(p, dict)]
			try:
				match_cue = float(data.get("match_cue") or match_cue)
			except (TypeError, ValueError):
				pass
	except Exception as e:
		log("status fetch:", e)
	return profiles, match_cue


def process_job(cfg: Dict[str, Any], job: Dict[str, Any]) -> Dict[str, Any]:
	file_name = str(job.get("file_name") or "")
	source_url = str(job.get("source_url") or "")
	if not file_name and source_url:
		file_name = urlsplit(source_url).path
	base = Path(file_name).name or "audio.mp3"
	set_activity("download", base)
	record_id = int(job.get("record_id") or 0)
	profiles = job.get("profiles") if isinstance(job.get("profiles"), list) else None
	match_cue = job.get("match_cue")
	if profiles is None:
		profiles, mc = fetch_profiles(cfg, record_id)
		if match_cue is None:
			match_cue = mc
	# Batch root may carry a union pack; narrow to this record's shift staff.
	raw_ids = job.get("profile_ids")
	if isinstance(raw_ids, list) and raw_ids and profiles:
		want = {int(x) for x in raw_ids if int(x or 0) > 0}
		if want:
			profiles = [
				p for p in profiles
				if isinstance(p, dict) and int(p.get("id_admin") or p.get("ID_ADMIN") or 0) in want
			]
	cfg_run = dict(cfg)
	match = dict(cfg_run.get("match") or {})
	if match_cue is not None:
		try:
			match["cue"] = float(match_cue)
		except (TypeError, ValueError):
			pass
	match_margin = job.get("match_margin")
	if match_margin is not None:
		try:
			match["margin"] = float(match_margin)
		except (TypeError, ValueError):
			pass
	# Skip under-enrolled centroids (e.g. 1×5s sample) — they steal turns from true speakers.
	match["min_sample_count"] = int(match.get("min_sample_count") or 3)
	match["min_talk_sec"] = float(match.get("min_talk_sec") or 15.0)
	cfg_run["match"] = match
	# Per-job A/B model / mode override (journal asr_model from FreeSaaS LongJob).
	job_model = str(job.get("asr_model") or job.get("asr_model_id") or "").strip()
	job_mode = str(job.get("asr_mode") or "").strip().lower()
	# Conventions: asr_model=reuse | reuse_gap → cabin transcript (+ optional gap_fill).
	ml = job_model.lower()
	if ml in ("reuse", "cabin_reuse", "cabin"):
		job_mode = "reuse"
	elif ml in ("reuse_gap", "reuse-gap", "cabin_gap"):
		job_mode = "reuse_gap"
	if job_model or job_mode or job.get("transcription"):
		asr = dict(cfg_run.get("asr") or {})
		if job_model and job_mode not in ("reuse", "reuse_gap"):
			asr["model_id"] = job_model
		if job_mode in ("words_align", "per_turn", "reuse", "reuse_gap", "cabin_reuse", "cabin", "cabin_gap"):
			if job_mode in ("reuse", "cabin_reuse", "cabin"):
				asr["mode"] = "reuse"
			elif job_mode in ("reuse_gap", "cabin_gap"):
				asr["mode"] = "reuse_gap"
			else:
				asr["mode"] = job_mode
		tr = job.get("transcription")
		if isinstance(tr, str) and tr.strip():
			asr["reuse_transcription"] = tr.strip()
		cfg_run["asr"] = asr

	with tempfile.TemporaryDirectory(prefix="asr_dia_job_") as tmp:
		local = Path(tmp) / base
		t_dl0 = time.time()
		download_audio(cfg, file_name, local, source_url)
		t_dl = time.time() - t_dl0
		set_activity("diarize", tl("activity.diarize", file=base))
		t_pipe0 = time.time()
		result = run_pipeline(local, cfg_run, profiles=list(profiles or []))
		t_pipe = time.time() - t_pipe0
		if not result.ok:
			raise RuntimeError(result.error or "pipeline_failed")
		set_activity("match", tl("activity.match", file=base))
		meta = dict(result.meta or {})
		meta["download_sec"] = round(t_dl, 2)
		meta["pipeline_sec"] = round(t_pipe, 2)
		meta["wall_sec"] = round(t_dl + t_pipe, 2)
		return {
			"kind": "asr_dia",
			"file_name": file_name,
			"record_id": int(job.get("record_id") or 0),
			"transcription": result.transcription,
			"speakers": result.speakers,
			"segments": result.segments,
			"cue_speakers": result.cue_speakers,
			"meta": meta,
			"apply_speakers": bool(job.get("apply_speakers", True)),
			# Default True: cabin divert needs text+DT stamp; PHP run flags remain SoT on apply.
			"apply_transcription": bool(job.get("apply_transcription", True)),
			"asr_model_id": str(((cfg_run.get("asr") or {}).get("model_id")) or ""),
		}


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
		capacity=int(push_cfg.get("capacity", 20)),
		max_unacked=int(push_cfg.get("max_unacked", 200)),
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
		worker_name=str(push_cfg.get("worker_name") or "asr_dia"),
		version=str(manifest.get("version") or ""),
		ready=lambda: True,
		wake=PUSH_WAKE,
		log=log,
	)
	port = int(manifest.get("local_api_port") or 18473)
	local_api.serve(port, api.handle, log=log)
	log(f"push API 127.0.0.1:{port} (gateway /v1/asr_dia/*)")


def process_push_job(cfg: Dict[str, Any]) -> bool:
	global PUSH_STORE
	if PUSH_STORE is None:
		return False
	job = PUSH_STORE.next_job()
	if not job:
		return False
	job_id = str(job.get("job_id") or "")
	bump_counter("files_req")
	try:
		result = process_job(cfg, job)
		PUSH_STORE.finish(job_id, result)
		bump_counter("files_ok")
		meta = result.get("meta") if isinstance(result.get("meta"), dict) else {}
		log(
			"done",
			Path(str(result.get("file_name") or "")).name,
			f"cues={meta.get('cues')} matched={meta.get('matched_cues')} pct={meta.get('matched_pct')}",
		)
	except Exception as e:
		bump_counter("files_err")
		requeued = PUSH_STORE.retry_later(job_id, str(e), PUSH_RETRY_DELAY, PUSH_MAX_ATTEMPTS)
		log("push job error:", e, "→", "retry" if requeued else "failed")
		if not requeued:
			raise
	return True


def main() -> int:
	global _STATUS_WRITER
	cfg = load_config()
	if StatusWriter is not None:
		_STATUS_WRITER = StatusWriter(STATUS_PATH, "asr_dia")
		_STATUS_WRITER.update(
			ok=True,
			meta={
				"server": str(cfg.get("server") or ""),
				"asr_model_id": str(((cfg.get("asr") or {}).get("model_id")) or ""),
				"dia_engine": str(((cfg.get("dia") or {}).get("engine")) or ""),
			},
			counters={"files_req": 0, "files_ok": 0, "files_err": 0},
		)
		set_activity("starting", "boot")
	start_push_mode(cfg)
	idle = int(((cfg.get("loop") or {}).get("sleep_seconds_idle")) or 30)
	run_sleep = int(((cfg.get("loop") or {}).get("sleep_seconds_run")) or 1)
	while True:
		try:
			if PUSH_STORE is not None:
				status_update(meta={"push": PUSH_STORE.counts()})
			had = False
			while process_push_job(cfg):
				had = True
				time.sleep(run_sleep)
			if had:
				continue
			set_activity("idle", tl("activity.push_wait"))
			if PUSH_WAKE is not None:
				PUSH_WAKE.wait(timeout=max(5, min(idle, 60)))
				PUSH_WAKE.clear()
			else:
				time.sleep(max(5, min(idle, 60)))
		except KeyboardInterrupt:
			status_update(ok=False)
			set_activity("idle", "stopped")
			return 0
		except Exception as e:
			log("ERROR:", e)
			traceback.print_exc()
			status_update(ok=False, meta={"last_error": str(e)[:300]})
			set_activity("error", str(e)[:200])
			time.sleep(max(5, idle // 2))


if __name__ == "__main__":
	raise SystemExit(main())
