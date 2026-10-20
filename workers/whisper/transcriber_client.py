#!/usr/bin/env python3
import argparse
import json
import os
import time
import warnings
from pathlib import Path
from datetime import datetime
import tempfile
import subprocess
import re
import math
from typing import Any, Dict, List, Optional, Tuple
import requests
import urllib3

from max_notify import send_message as max_send_message

warnings.filterwarnings("ignore", category=FutureWarning)

# Настройки клиента (заполняются из JSON через load_config / --config)
# FreeSaaS: claim/result/stem + X-Audio-Token (см. docs/agents/audio_pipeline.md).
SERVER = ""
FTPSERVER = ""
WORKER_TOKEN = ""
GET_FILE_URL = ""
SAVE_RESULT_URL = ""
ANALYTICS_URL = ""
# TLS for FreeSaaS (+ HTTPS audio host): True | False | path to CA bundle.
TLS_VERIFY: Any = True
UPLOAD_RETRIES = 3
UPLOAD_RETRY_SLEEP_SEC = 2.0
ANALYTICS_MAX_RUNS_PER_IDLE = 2
ANALYTICS_MIN_INTERVAL_SEC = 300
WHISPER_SERVER_HOST = "127.0.0.1"
WHISPER_SERVER_PORT = 17871
WHISPER_SERVER_BASE = ""
WHISPER_SERVER_URL = ""
WHISPER_SERVER_HEALTH_URL = ""
WHISPER_DOWN_RECHECK_SEC = 2.0
WHISPER_AUTO_NOVAD_FALLBACK = True
WHISPER_START_SCRIPT = Path(__file__).resolve().parent / "start_whisper_server.sh"
WHISPER_HEALTH_WAIT_SEC = 180.0
WHISPER_RESTART_SETTLE_SEC = 2.0
# Текущий режим сервера (после авто-рестарта клиентом). До первого рестарта — предполагаем VAD.
WHISPER_MODE_VAD = True
WS_FORM_PARAMS: Dict[str, str] = {}
GARBAGE_MIN_LETTERS = 15
GARBAGE_CJK_RATIO = 0.12
GARBAGE_MIN_CJK_CHARS = 22
RETRY_TEMPERATURES_ON_GARBAGE: Tuple[float, ...] = (0.1, 0.28)
RETRY_TEMPERATURES_ON_EMPTY_RESPONSE: Tuple[float, ...] = RETRY_TEMPERATURES_ON_GARBAGE
LOOP_SLEEP_SECONDS_IDLE = 180
LOOP_SLEEP_SECONDS_RUN = 1
SLEEP_HOUR = 24
MAX_TOKEN = ""
MAX_CHAT_ID = ""
MAX_NOTIFY_WHISPER_DOWN = True
MAX_NOTIFY_RECOGNITION_SUMMARY = True
MAX_NOTIFY_NOVAD_FALLBACK = True

# Copy configs/example.json → configs/local.json (gitignored) and fill secrets.
DEFAULT_CONFIG_PATH = Path(__file__).resolve().parent / "configs" / "local.json"
CLIENT_ROOT = Path(__file__).resolve().parent
# Local copy of transcripts not yet acked by FreeSaaS (avoid re-whisper after failed POST).
PENDING_RESULTS_DIR = CLIENT_ROOT / "var" / "pending_results"


class BackendUploadError(RuntimeError):
	"""FreeSaaS rejected or did not confirm saving a transcription (do not mark file done)."""


def backend_headers() -> Dict[str, str]:
	"""Auth for FreeSaaS /audio/asr/* and /audio/jobs/* (oper gens/* did not need this)."""
	if not WORKER_TOKEN:
		return {}
	return {"X-Audio-Token": WORKER_TOKEN}


def tls_verify() -> Any:
	"""requests `verify=` value: True, False, or CA bundle path."""
	return TLS_VERIFY


def load_config(config_path: Path) -> None:
	"""Читает JSON-конфиг клиента и выставляет глобальные настройки обработчика."""
	global SERVER, FTPSERVER, WORKER_TOKEN, GET_FILE_URL, SAVE_RESULT_URL
	global ANALYTICS_URL, ANALYTICS_MAX_RUNS_PER_IDLE, ANALYTICS_MIN_INTERVAL_SEC
	global TLS_VERIFY, UPLOAD_RETRIES, UPLOAD_RETRY_SLEEP_SEC
	global WHISPER_SERVER_HOST, WHISPER_SERVER_PORT
	global WHISPER_SERVER_BASE, WHISPER_SERVER_URL, WHISPER_SERVER_HEALTH_URL
	global WHISPER_DOWN_RECHECK_SEC, WS_FORM_PARAMS
	global WHISPER_AUTO_NOVAD_FALLBACK, WHISPER_START_SCRIPT
	global WHISPER_HEALTH_WAIT_SEC, WHISPER_RESTART_SETTLE_SEC, WHISPER_MODE_VAD
	global GARBAGE_MIN_LETTERS, GARBAGE_CJK_RATIO, GARBAGE_MIN_CJK_CHARS
	global RETRY_TEMPERATURES_ON_GARBAGE, RETRY_TEMPERATURES_ON_EMPTY_RESPONSE
	global LOOP_SLEEP_SECONDS_IDLE, LOOP_SLEEP_SECONDS_RUN, SLEEP_HOUR
	global MAX_TOKEN, MAX_CHAT_ID, MAX_NOTIFY_WHISPER_DOWN, MAX_NOTIFY_RECOGNITION_SUMMARY
	global MAX_NOTIFY_NOVAD_FALLBACK

	with open(config_path, "r", encoding="utf-8") as f:
		cfg = json.load(f)

	SERVER = str(cfg["server"]).rstrip("/")
	FTPSERVER = str(cfg["ftp_server"]).rstrip("/")
	WORKER_TOKEN = str(cfg.get("worker_token") or cfg.get("audio_token") or "").strip()

	tls = cfg.get("tls") or {}
	ca_raw = str(tls.get("ca_bundle") or cfg.get("tls_ca_bundle") or "").strip()
	verify_raw = tls.get("verify", cfg.get("tls_verify", True))
	if ca_raw:
		ca_path = Path(ca_raw).expanduser()
		if not ca_path.is_absolute():
			ca_path = (CLIENT_ROOT / ca_path).resolve()
		if not ca_path.is_file():
			raise SystemExit(f"tls.ca_bundle not found: {ca_path}")
		TLS_VERIFY = str(ca_path)
	elif verify_raw is False or verify_raw == 0 or str(verify_raw).lower() in ("false", "0", "no"):
		TLS_VERIFY = False
		urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
	else:
		TLS_VERIFY = True

	UPLOAD_RETRIES = max(1, int(tls.get("upload_retries", cfg.get("upload_retries", 3))))
	UPLOAD_RETRY_SLEEP_SEC = float(tls.get("upload_retry_sleep_sec", 2.0))

	ep = cfg.get("endpoints") or {}
	# FreeSaaS defaults (oper: /gens/getFileName.php, saveFileDescription.php, recordParser3.php)
	GET_FILE_URL = f"{SERVER}{ep.get('get_file', '/audio/asr/claim')}"
	SAVE_RESULT_URL = f"{SERVER}{ep.get('save_result', '/audio/asr/result')}"
	ANALYTICS_URL = f"{SERVER}{ep.get('analytics', '/audio/jobs/stem')}"

	an = cfg.get("analytics") or {}
	ANALYTICS_MAX_RUNS_PER_IDLE = int(an.get("max_runs_per_idle", 2))
	ANALYTICS_MIN_INTERVAL_SEC = int(an.get("min_interval_sec", 300))

	wh = cfg.get("whisper") or {}
	WHISPER_SERVER_HOST = str(wh.get("host", "127.0.0.1"))
	WHISPER_SERVER_PORT = int(wh.get("port", 17871))
	WHISPER_SERVER_BASE = f"http://{WHISPER_SERVER_HOST}:{WHISPER_SERVER_PORT}"
	WHISPER_SERVER_URL = f"{WHISPER_SERVER_BASE}/inference"
	WHISPER_SERVER_HEALTH_URL = f"{WHISPER_SERVER_BASE}/health"
	WHISPER_DOWN_RECHECK_SEC = float(wh.get("down_recheck_sec", 2.0))
	WHISPER_AUTO_NOVAD_FALLBACK = bool(wh.get("auto_novad_fallback", True))
	WHISPER_HEALTH_WAIT_SEC = float(wh.get("health_wait_sec", 180))
	WHISPER_RESTART_SETTLE_SEC = float(wh.get("restart_settle_sec", 2.0))
	script_raw = str(wh.get("start_script") or "start_whisper_server.sh").strip()
	script_path = Path(script_raw).expanduser()
	if not script_path.is_absolute():
		script_path = (CLIENT_ROOT / script_path).resolve()
	WHISPER_START_SCRIPT = script_path
	WHISPER_MODE_VAD = True
	form = wh.get("form_params") or {}
	WS_FORM_PARAMS = {str(k): str(v) for k, v in form.items()}
	if "temperature" not in WS_FORM_PARAMS:
		WS_FORM_PARAMS["temperature"] = "0.0"

	gd = cfg.get("garbage_detection") or {}
	GARBAGE_MIN_LETTERS = int(gd.get("min_letters", 15))
	GARBAGE_CJK_RATIO = float(gd.get("cjk_ratio", 0.12))
	GARBAGE_MIN_CJK_CHARS = int(gd.get("min_cjk_chars", 22))
	temps: List[float] = [float(t) for t in gd.get("retry_temperatures", [0.1, 0.28])]
	RETRY_TEMPERATURES_ON_GARBAGE = tuple(temps)
	RETRY_TEMPERATURES_ON_EMPTY_RESPONSE = RETRY_TEMPERATURES_ON_GARBAGE

	lp = cfg.get("loop") or {}
	LOOP_SLEEP_SECONDS_IDLE = int(lp.get("sleep_seconds_idle", 180))
	LOOP_SLEEP_SECONDS_RUN = int(lp.get("sleep_seconds_run", 1))
	SLEEP_HOUR = int(lp.get("sleep_hour", 24))

	mx = cfg.get("max") or {}
	MAX_TOKEN = str(mx.get("token") or "").strip()
	MAX_CHAT_ID = str(mx.get("chat_id") or "").strip()
	notify = mx.get("notify") or {}
	MAX_NOTIFY_WHISPER_DOWN = bool(notify.get("whisper_down", True))
	MAX_NOTIFY_RECOGNITION_SUMMARY = bool(notify.get("recognition_summary", True))
	MAX_NOTIFY_NOVAD_FALLBACK = bool(notify.get("novad_fallback", True))


def parse_args() -> argparse.Namespace:
	p = argparse.ArgumentParser(description="Клиент транскрибации whisper-server + бэкенд")
	p.add_argument(
		"--config",
		"-c",
		type=Path,
		default=DEFAULT_CONFIG_PATH,
		help=f"JSON с настройками клиента (по умолчанию: {DEFAULT_CONFIG_PATH})",
	)
	return p.parse_args()


def log(*args):
	print(f"[{datetime.now().strftime('%H:%M:%S')}] ", *args, flush=True)

def notify_max(text: str) -> None:
	"""Отправка в Max; при пустых credentials — no-op. Ошибки только в лог."""
	if not MAX_TOKEN or not MAX_CHAT_ID:
		log("ℹ️ Max: token/chat_id не заданы — уведомление пропущено.")
		return
	ok, msg = max_send_message(MAX_TOKEN, MAX_CHAT_ID, text)
	if ok:
		log("📨 Max: уведомление отправлено.")
	else:
		log("⚠️ Max: не удалось отправить:", msg)

def format_duration(seconds: float) -> str:
	total = max(0, int(seconds))
	h, rem = divmod(total, 3600)
	m, _ = divmod(rem, 60)
	if h > 0:
		return f"{h}h {m}m"
	return f"{m}m"

def sleep_mac():
	log("🌙 Все файлы обработаны. Переводим Mac в режим сна...")
	try:
		subprocess.run(["osascript", "-e", 'tell application "System Events" to sleep'])
	except Exception as e:
		log("⚠️ Ошибка при попытке усыпить Mac:", e)

def is_whisper_server_unreachable_error(exc: BaseException) -> bool:
	"""
	True если локальный whisper-server, скорее всего, мёртв/упал:
	connection refused, reset, remote closed mid-request и т.п.
	Такие ошибки НЕ надо сдавать на бэкенд как «результат файла».
	"""
	if isinstance(
		exc,
		(
			requests.exceptions.ConnectionError,
			ConnectionRefusedError,
			ConnectionResetError,
			BrokenPipeError,
		),
	):
		return True
	msg = str(exc).lower()
	needles = (
		"connection refused",
		"failed to establish a new connection",
		"max retries exceeded",
		"errno 61",
		"connection reset",
		"remote end closed connection",
		"remotely closed",
		"connection aborted",
		"broken pipe",
		"nodename nor servname provided",
	)
	return any(n in msg for n in needles)

def whisper_server_health_ok(timeout: float = 3.0) -> bool:
	"""GET /health → True только при HTTP 200 (модель готова)."""
	try:
		r = requests.get(WHISPER_SERVER_HEALTH_URL, timeout=timeout)
		return r.status_code == 200
	except Exception:
		return False

def whisper_port_listening() -> bool:
	"""True если на WHISPER_SERVER_PORT кто-то слушает (macOS lsof)."""
	try:
		r = subprocess.run(
			["lsof", f"-iTCP:{WHISPER_SERVER_PORT}", "-sTCP:LISTEN"],
			capture_output=True,
			text=True,
			timeout=5,
		)
		return r.returncode == 0 and bool((r.stdout or "").strip())
	except Exception:
		return False

def wait_whisper_health(timeout_sec: Optional[float] = None, poll_sec: float = 2.0) -> bool:
	"""Ждёт HTTP 200 на /health. Возвращает True при успехе."""
	limit = WHISPER_HEALTH_WAIT_SEC if timeout_sec is None else float(timeout_sec)
	deadline = time.time() + limit
	log(f"⏳ Ждём /health до {limit:.0f} сек ({WHISPER_SERVER_HEALTH_URL})...")
	while time.time() < deadline:
		if whisper_server_health_ok():
			log("✅ whisper-server /health OK")
			return True
		time.sleep(poll_sec)
	log("❌ Таймаут ожидания /health")
	return False

def kill_whisper_server(force_after_sec: float = 8.0) -> None:
	"""Останавливает whisper-server на нашем порту (SIGTERM, затем SIGKILL)."""
	pattern = f"whisper-server.*--port {WHISPER_SERVER_PORT}"
	log(f"🛑 Останавливаем whisper-server (pkill -f {pattern!r})...")
	subprocess.run(["pkill", "-f", pattern], capture_output=True)
	deadline = time.time() + force_after_sec
	while time.time() < deadline:
		if not whisper_server_health_ok(timeout=1.0) and not whisper_port_listening():
			log("🛑 whisper-server остановлен")
			return
		time.sleep(0.5)
	log("🛑 Принудительно SIGKILL...")
	subprocess.run(["pkill", "-9", "-f", pattern], capture_output=True)
	time.sleep(0.5)

def start_whisper_server(vad: bool) -> None:
	"""
	Запускает start_whisper_server.sh в фоне (detached).
	Логи пишет сам скрипт в logs/; stdout/stderr Popen глушим.
	"""
	global WHISPER_MODE_VAD
	if not WHISPER_START_SCRIPT.is_file():
		raise FileNotFoundError(f"Скрипт запуска не найден: {WHISPER_START_SCRIPT}")
	mode = "VAD" if vad else "novad"
	env = os.environ.copy()
	env["WHISPER_VAD"] = "1" if vad else "0"
	log(f"🚀 Старт whisper-server ({mode}): {WHISPER_START_SCRIPT}")
	subprocess.Popen(
		[str(WHISPER_START_SCRIPT)],
		cwd=str(CLIENT_ROOT),
		env=env,
		stdout=subprocess.DEVNULL,
		stderr=subprocess.DEVNULL,
		stdin=subprocess.DEVNULL,
		start_new_session=True,
	)
	WHISPER_MODE_VAD = vad
	time.sleep(WHISPER_RESTART_SETTLE_SEC)

def restart_whisper_server(vad: bool) -> bool:
	"""Kill → start → wait /health. True если сервер поднялся."""
	kill_whisper_server()
	start_whisper_server(vad=vad)
	return wait_whisper_health()

def fatal_whisper_server_down(detail: str) -> None:
	"""Громкий стоп: локальный сервер лёг и сам не поднимется."""
	banner = (
		"\n"
		"!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n"
		"!!!                                                              !!!\n"
		"!!!   WHISPER-SERVER НЕДОСТУПЕН — ОСТАНАВЛИВАЕМ КЛИЕНТ           !!!\n"
		"!!!   Файл НЕ отмечен ошибкой на бэкенде — останется в очереди   !!!\n"
		"!!!   Поднимите whisper-server и перезапустите этот скрипт       !!!\n"
		"!!!                                                              !!!\n"
		"!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n"
	)
	print(banner, flush=True)
	log("FATAL DETAIL:", detail)
	log(
		f"FATAL: проверка {WHISPER_SERVER_HEALTH_URL} не прошла. "
		"Локальный сервер не оживёт сам — чините процесс и стартуйте заново."
	)
	if MAX_NOTIFY_WHISPER_DOWN:
		notify_max(
			"❌ Whisper-server недоступен — клиент остановлен<br>"
			f"Сервер: {SERVER}<br>"
			f"Whisper: {WHISPER_SERVER_BASE}<br>"
			f"Деталь: {detail}"
		)
	raise SystemExit(2)

def handle_whisper_server_down(exc: BaseException) -> None:
	"""
	Повторно дергаем /health; если снова провал — SystemExit.
	На бэкенд ничего не отправляем.
	"""
	log("❌ whisper-server недоступен (связь оборвана):", exc)
	log(f"🔍 Повторная проверка /health через {WHISPER_DOWN_RECHECK_SEC:.0f} сек...")
	time.sleep(WHISPER_DOWN_RECHECK_SEC)
	if whisper_server_health_ok():
		# Крайне редко: краткий обрыв. Файл всё равно не помечаем — пусть вернётся в очередь.
		log(
			"⚠️ /health снова отвечает OK после обрыва. "
			"Результат на бэкенд НЕ отправлен — файл останется в очереди на следующий цикл."
		)
		return
	fatal_whisper_server_down(str(exc))

def recover_crash_with_novad(
	audio_path: Path,
	file_name: str,
	playtime: Any,
	volume: Any,
	crash_detail: str,
) -> str:
	"""
	После падения whisper (обычно VAD segfault):
	  1) поднять novad, повторить файл
	  2) вернуть VAD для следующих файлов

	Возвращает:
	  'ok' — файл обработан (транскрипт или error marker), сервер желательно на VAD
	  'transient' — /health ожил сам, файл не обработан (повторить в очереди)

	При невосстановимом сбое — SystemExit через fatal_whisper_server_down.
	"""
	log("❌ whisper-server недоступен (связь оборвана):", crash_detail)
	log(f"🔍 Повторная проверка /health через {WHISPER_DOWN_RECHECK_SEC:.0f} сек...")
	time.sleep(WHISPER_DOWN_RECHECK_SEC)
	if whisper_server_health_ok():
		log(
			"⚠️ /health снова отвечает OK после обрыва. "
			"Результат на бэкенд НЕ отправлен — файл останется в очереди на следующий цикл."
		)
		return "transient"

	if not WHISPER_MODE_VAD:
		fatal_whisper_server_down(
			f"Падение уже в novad-режиме, повторный fallback отключён. {crash_detail}"
		)

	fname = Path(file_name).name
	if MAX_NOTIFY_NOVAD_FALLBACK:
		notify_max(
			"⚠️ Whisper-server упал (вероятно VAD) — переключаюсь на novad<br>"
			f"Сервер: {SERVER}<br>"
			f"Файл: {fname}<br>"
			f"Деталь: {crash_detail}"
		)

	log("🔁 Fallback: перезапуск whisper-server БЕЗ VAD для проблемного файла...")
	if not restart_whisper_server(vad=False):
		fatal_whisper_server_down(
			f"Не удалось поднять whisper-server в novad после краша. Исходная ошибка: {crash_detail}"
		)

	# Повтор проблемного файла на novad
	try:
		log("🧠 Повтор на novad (output=srt)...")
		transcribe_upload_srt(audio_path, file_name, playtime=playtime, volume=volume)
	except BackendUploadError:
		# Transcript ready but FreeSaaS did not confirm — leave claim for retry.
		raise
	except Exception as e:
		if is_whisper_server_unreachable_error(e):
			fatal_whisper_server_down(
				f"novad тоже упал на файле {fname}: {e}"
			)
		log("❌ Ошибка на novad (сервер жив):", e)
		upload_error_marker(file_name, str(e), playtime=playtime, volume=volume)

	# Вернуть VAD для следующих файлов
	log("🔁 Возвращаем whisper-server С VAD...")
	if MAX_NOTIFY_NOVAD_FALLBACK:
		notify_max(
			"ℹ️ Файл обработан на novad, возвращаю VAD<br>"
			f"Сервер: {SERVER}<br>"
			f"Файл: {fname}"
		)
	if not restart_whisper_server(vad=True):
		# Файл уже сдан на бэкенд; остаёмся на novad если он ещё жив, иначе fatal.
		if whisper_server_health_ok() or wait_whisper_health(timeout_sec=5):
			log("⚠️ VAD не поднялся — продолжаем на текущем (novad) сервере.")
			if MAX_NOTIFY_NOVAD_FALLBACK:
				notify_max(
					"⚠️ Не удалось вернуть VAD — продолжаю на novad<br>"
					f"Сервер: {SERVER}"
				)
		else:
			# Попытка хотя бы novad
			if restart_whisper_server(vad=False):
				log("⚠️ VAD не поднялся, снова на novad.")
				if MAX_NOTIFY_NOVAD_FALLBACK:
					notify_max(
						"⚠️ Не удалось вернуть VAD — работаю на novad<br>"
						f"Сервер: {SERVER}"
					)
			else:
				fatal_whisper_server_down(
					f"Файл {fname} обработан, но whisper-server не поднялся ни с VAD, ни без."
				)
	else:
		if MAX_NOTIFY_NOVAD_FALLBACK:
			notify_max(
				"✅ Whisper-server снова на VAD, продолжаем<br>"
				f"Сервер: {SERVER}"
			)

	return "ok"

# ---------- Форматирование времени и SRT ----------

def mmss_floor(x: float) -> str:
	t = max(0, int(math.floor(x)))
	m, s = divmod(t, 60)
	return f"{m:02}:{s:02}"

def mmss_ceil(x: float) -> str:
	t = max(0, int(math.ceil(x)))
	m, s = divmod(t, 60)
	return f"{m:02}:{s:02}"

def srt_text_to_custom(srt_text: str) -> str:
	"""
	Конвертирует SRT-текст в ваш формат:
	[MM:SS->MM:SS] text
	Делает мягкое выравнивание: start=floor, end=ceil, минимум 1 сек.
	"""
	blocks = re.split(r"\n\s*\n", srt_text.strip(), flags=re.MULTILINE)
	out_lines = []
	for block in blocks:
		lines = [ln.strip() for ln in block.splitlines() if ln.strip()]
		if len(lines) < 2:
			continue
		# SRT-таймкоды во 2-й строке
		m = re.search(
			r"(\d{2}):(\d{2}):(\d{2}),\d{3}\s*-->\s*(\d{2}):(\d{2}):(\d{2}),\d{3}",
			lines[1],
		)
		if not m:
			# иногда индекса нет: тогда первая строка сразу time-range
			m = re.search(
				r"(\d{2}):(\d{2}):(\d{2}),\d{3}\s*-->\s*(\d{2}):(\d{2}):(\d{2}),\d{3}",
				lines[0],
			)
			text_lines = lines[1:] if m else lines
		else:
			text_lines = lines[2:]

		if not m:
			continue

		h1, m1, s1, h2, m2, s2 = map(int, m.groups())
		start = h1 * 3600 + m1 * 60 + s1
		end = h2 * 3600 + m2 * 60 + s2

		# минимальная длительность, чтобы не было 00:SS->00:SS
		if end - start < 1:
			end = start + 1

		text = " ".join(text_lines).strip()
		if not text:
			continue

		out_lines.append(f"[{mmss_floor(start)}->{mmss_ceil(end)}] {text}")

	return "\n".join(out_lines)

def _custom_format_body_only(formatted: str) -> str:
	"""Только текст сегментов, без префиксов [MM:SS->MM:SS]."""
	parts = []
	for line in formatted.splitlines():
		line = line.strip()
		if not line:
			continue
		if "] " in line:
			parts.append(line.split("]", 1)[1].lstrip())
		else:
			parts.append(line)
	return " ".join(parts)

def _is_non_ascii_letter(ch: str) -> bool:
	return ch.isalpha() and ord(ch) > 127

def _is_cjk_like_script(ch: str) -> bool:
	o = ord(ch)
	return (
		0x3040 <= o <= 0x30FF  # Hiragana, Katakana
		or 0x31F0 <= o <= 0x31FF  # Katakana phonetic extensions
		or 0xAC00 <= o <= 0xD7AF  # Hangul syllables
		or 0x1100 <= o <= 0x11FF  # Hangul Jamo
		or 0x4E00 <= o <= 0x9FFF  # CJK Unified Ideographs
		or 0x3400 <= o <= 0x4DBF  # CJK Extension A
	)

def transcription_garbage_metrics(formatted: str) -> tuple[int, int, float]:
	"""
	Возвращает (число «букв» не ASCII, число CJK-подобных, доля CJK среди не-ASCII букв).
	ASCII латиница в русской записи редка — не считаем её за якорь качества.
	"""
	body = _custom_format_body_only(formatted)
	non_ascii_letters = 0
	cjk_like = 0
	for ch in body:
		if not _is_non_ascii_letter(ch):
			continue
		non_ascii_letters += 1
		if _is_cjk_like_script(ch):
			cjk_like += 1
	ratio = (cjk_like / non_ascii_letters) if non_ascii_letters else 0.0
	return non_ascii_letters, cjk_like, ratio

def transcription_looks_like_script_garbage(formatted: str) -> bool:
	"""True если текст похож на массовую подмену кириллицы иероглифами и т.п."""
	body = _custom_format_body_only(formatted)
	if "\ufffd" in body:
		return True
	n_letters, cjk_n, ratio = transcription_garbage_metrics(formatted)
	if n_letters >= GARBAGE_MIN_LETTERS and ratio >= GARBAGE_CJK_RATIO:
		return True
	if cjk_n >= GARBAGE_MIN_CJK_CHARS:
		return True
	return False

def transcription_garbage_score(formatted: str) -> float:
	"""Меньше — лучше (для выбора лучшего из нескольких прогонов)."""
	body = _custom_format_body_only(formatted)
	if "\ufffd" in body:
		return 10_000.0 + len(body)
	_, cjk_n, ratio = transcription_garbage_metrics(formatted)
	return ratio * 1000.0 + cjk_n

def custom_format_nonempty_line_count(formatted: str) -> int:
	"""Число непустых строк в кастомном формате (обычно одна строка = один сегмент)."""
	return sum(1 for ln in formatted.splitlines() if ln.strip())

def transcription_quality_key(formatted: str) -> tuple:
	"""
	Ключ для сравнения: больше — лучше.
	Сначала число сегментов (строк), затем меньший garbage_score, затем длина текста.
	"""
	body = _custom_format_body_only(formatted)
	n = custom_format_nonempty_line_count(formatted)
	g = transcription_garbage_score(formatted)
	return (n, -g, len(body))

def pick_better_transcription(a: str, b: str) -> str:
	return a if transcription_quality_key(a) >= transcription_quality_key(b) else b

# ---------- Сеть ----------

def download_file(url: str, local_path: Path):
	log(f"⬇️  Скачиваем {url}")
	r = requests.get(url, verify=tls_verify(), timeout=60)
	r.raise_for_status()
	with open(local_path, "wb") as f:
		f.write(r.content)

EMPTY_WHISPER_SERVER_RESPONSE = "Пустой ответ от whisper-server"

def whisper_response_to_error_if_any(body: str):
	"""
	whisper-server иногда возвращает JSON вида {"error":"failed to read audio data"}
	вместо HTTP error. Проверяем до парсинга SRT.
	"""
	if body is None:
		return None
	t = str(body).strip()
	if t == "":
		return EMPTY_WHISPER_SERVER_RESPONSE

	if t.startswith("{") and t.endswith("}"):
		try:
			obj = json.loads(t)
			if isinstance(obj, dict) and "error" in obj:
				err = obj.get("error")
				if err is None:
					err = "whisper error"
				return str(err)
		except Exception:
			pass

	tl = t.lower()
	if "failed to read audio data" in tl:
		return "failed to read audio data"

	return None

def convert_audio_to_mp3(input_path: Path) -> Path:
	"""
	Перекодирование через ffmpeg (моно 16 kHz MP3), как в voice_transcribe_poll.py.
	Помогает, если исходный контейнер/кодек whisper-server не читает.
	"""
	out_path = input_path.with_suffix(".mp3")

	cmd = [
		"ffmpeg",
		"-y",
		"-loglevel",
		"error",
		"-i",
		str(input_path),
		"-vn",
		"-ac",
		"1",
		"-ar",
		"16000",
		"-acodec",
		"libmp3lame",
		str(out_path),
	]
	try:
		p = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
	except FileNotFoundError:
		raise RuntimeError("ffmpeg не найден (нужен для mp3 retry)")
	except subprocess.TimeoutExpired:
		raise RuntimeError("ffmpeg timeout при конвертации в mp3")

	if p.returncode != 0:
		tail = (p.stderr or "").strip()
		raise RuntimeError(f"ffmpeg failed (rc={p.returncode}): {tail[:220]}")

	if not out_path.exists() or out_path.stat().st_size <= 0:
		raise RuntimeError("ffmpeg created empty mp3")

	return out_path

def _run_ffprobe_json(audio_path: Path) -> Dict[str, Any]:
	cmd = [
		"ffprobe",
		"-v",
		"quiet",
		"-print_format",
		"json",
		"-show_format",
		"-show_streams",
		str(audio_path),
	]
	try:
		p = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
	except FileNotFoundError:
		raise RuntimeError("ffprobe не найден (нужен для PLAYTIME/VOLUME)")
	except subprocess.TimeoutExpired:
		raise RuntimeError("ffprobe timeout")

	if p.returncode != 0:
		tail = (p.stderr or p.stdout or "").strip()
		raise RuntimeError(f"ffprobe failed (rc={p.returncode}): {tail[:220]}")

	try:
		data = json.loads(p.stdout or "{}")
	except json.JSONDecodeError as e:
		raise RuntimeError(f"ffprobe: невалидный JSON: {e}") from e
	if not isinstance(data, dict):
		raise RuntimeError("ffprobe: неожиданный формат ответа")
	return data

def _parse_db_gain_value(raw: str) -> Optional[float]:
	if not raw:
		return None
	m = re.search(r"([-+]?\d+(?:\.\d+)?)", str(raw).strip())
	if not m:
		return None
	try:
		return float(m.group(1))
	except ValueError:
		return None

def _collect_ffprobe_tags(probe: Dict[str, Any]) -> Dict[str, str]:
	out: Dict[str, str] = {}
	fmt = probe.get("format")
	if isinstance(fmt, dict):
		tags = fmt.get("tags")
		if isinstance(tags, dict):
			for k, v in tags.items():
				if v is not None:
					out[str(k).upper()] = str(v)
	for stream in probe.get("streams") or []:
		if not isinstance(stream, dict):
			continue
		tags = stream.get("tags")
		if isinstance(tags, dict):
			for k, v in tags.items():
				if v is not None:
					out[str(k).upper()] = str(v)
	return out

def _playtime_from_probe(probe: Dict[str, Any]) -> Optional[float]:
	best = 0.0
	fmt = probe.get("format")
	if isinstance(fmt, dict) and fmt.get("duration") is not None:
		try:
			best = max(best, float(fmt["duration"]))
		except (TypeError, ValueError):
			pass

	for stream in probe.get("streams") or []:
		if not isinstance(stream, dict):
			continue
		if stream.get("codec_type") not in (None, "audio"):
			continue
		if stream.get("duration") is None:
			continue
		try:
			best = max(best, float(stream["duration"]))
		except (TypeError, ValueError):
			pass

	return best if best > 0 else None

def _replaygain_db_from_tags(tags: Dict[str, str]) -> Optional[float]:
	for key in ("REPLAYGAIN_TRACK_GAIN", "REPLAYGAIN_ALBUM_GAIN"):
		raw = tags.get(key)
		if not raw:
			continue
		gain = _parse_db_gain_value(raw)
		if gain is not None:
			return gain
	return None

def _mean_volume_db_ffmpeg(audio_path: Path) -> Optional[float]:
	"""Запасной вариант, если ReplayGain в тегах нет."""
	cmd = [
		"ffmpeg",
		"-hide_banner",
		"-nostats",
		"-i",
		str(audio_path),
		"-af",
		"volumedetect",
		"-f",
		"null",
		"-",
	]
	try:
		p = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
	except FileNotFoundError:
		return None
	except subprocess.TimeoutExpired:
		return None

	text = (p.stderr or "") + (p.stdout or "")
	m = re.search(r"mean_volume:\s*([-+]?\d+(?:\.\d+)?)\s*dB", text)
	if m:
		try:
			return float(m.group(1))
		except ValueError:
			return None
	return None

def _volume_abs_from_probe(audio_path: Path, probe: Dict[str, Any]) -> Optional[float]:
	tags = _collect_ffprobe_tags(probe)
	gain_db = _replaygain_db_from_tags(tags)
	if gain_db is None:
		gain_db = _mean_volume_db_ffmpeg(audio_path)
		if gain_db is None:
			return None
	return abs(gain_db)

def probe_audio_metadata(audio_path: Path) -> Tuple[Optional[float], Optional[float]]:
	"""Один ffprobe на файл; VOLUME = |ReplayGain| или |mean_volume|."""
	playtime: Optional[float] = None
	volume: Optional[float] = None
	try:
		probe = _run_ffprobe_json(audio_path)
		playtime = _playtime_from_probe(probe)
		volume = _volume_abs_from_probe(audio_path, probe)
	except Exception as e:
		log("⚠️ Метаданные аудио:", e)

	if playtime is None:
		log("⚠️ PLAYTIME: длительность не найдена в", audio_path.name)
	if volume is None:
		log("⚠️ VOLUME: громкость не найдена в", audio_path.name)
	if playtime is not None or volume is not None:
		log(
			f"📏 Метаданные: PLAYTIME={playtime if playtime is not None else '—'} с, "
			f"VOLUME={volume if volume is not None else '—'}"
		)
	return playtime, volume

def parse_result_ack(body: str) -> bool:
	"""
	/audio/asr/result body: first line is JSON true/false (then optional diag).
	Only true counts as saved — HTTP 200 + false must not clear the claim.
	"""
	first = (body or "").lstrip("\ufeff").splitlines()[:1]
	if not first:
		return False
	line = first[0].strip()
	try:
		return json.loads(line) is True
	except Exception:
		return line.lower() == "true"


def _pending_path(file_name: str) -> Path:
	safe = re.sub(r"[^a-zA-Z0-9._-]+", "_", Path(file_name).name) or "pending"
	return PENDING_RESULTS_DIR / f"{safe}.json"


def save_pending_result(
	file_name: str,
	text: str,
	playtime: Optional[float] = None,
	volume: Optional[float] = None,
) -> None:
	PENDING_RESULTS_DIR.mkdir(parents=True, exist_ok=True)
	payload = {
		"file_name": file_name,
		"result": text,
		"PLAYTIME": playtime,
		"VOLUME": volume,
		"saved_at": time.time(),
	}
	path = _pending_path(file_name)
	tmp = path.with_suffix(".tmp")
	tmp.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
	tmp.replace(path)


def load_pending_result(file_name: str) -> Optional[Dict[str, Any]]:
	path = _pending_path(file_name)
	if not path.is_file():
		return None
	try:
		data = json.loads(path.read_text(encoding="utf-8"))
		if not isinstance(data, dict) or not str(data.get("result", "")):
			return None
		return data
	except Exception as e:
		log("⚠️ pending result unreadable:", path.name, e)
		return None


def clear_pending_result(file_name: str) -> None:
	path = _pending_path(file_name)
	try:
		path.unlink(missing_ok=True)
	except Exception:
		pass


def upload_transcription(
	file_name: str,
	text: str,
	playtime: Optional[float] = None,
	volume: Optional[float] = None,
):
	"""
	POST transcript to FreeSaaS. Raises BackendUploadError unless the server
	confirms with HTTP 2xx and body starting with JSON true.
	Retries the same text (no re-whisper) on transient failure; keeps a local
	pending copy until ack so a later claim can re-POST without re-ASR.
	"""
	save_pending_result(file_name, text, playtime=playtime, volume=volume)
	log(f"📤 Отправляем результат для {file_name}")
	data = {"file_name": file_name, "result": text}
	if playtime is not None and playtime > 0:
		data["PLAYTIME"] = str(int(round(playtime)))
	if volume is not None:
		data["VOLUME"] = f"{volume:.2f}".rstrip("0").rstrip(".")

	last_err: Optional[BaseException] = None
	for attempt in range(1, UPLOAD_RETRIES + 1):
		try:
			r = requests.post(
				SAVE_RESULT_URL,
				data=data,
				headers=backend_headers(),
				verify=tls_verify(),
				timeout=60,
			)
			body_preview = (r.text or "")[:240]
			if r.status_code < 200 or r.status_code >= 300:
				raise BackendUploadError(
					f"HTTP {r.status_code}: {body_preview}"
				)
			if not parse_result_ack(r.text or ""):
				raise BackendUploadError(
					f"server did not ack save (need JSON true): {body_preview}"
				)
			clear_pending_result(file_name)
			log("✅ Результат сохранён:", r.status_code, body_preview)
			return
		except BackendUploadError as e:
			last_err = e
			log(f"⚠️ Upload attempt {attempt}/{UPLOAD_RETRIES} failed:", e)
		except requests.RequestException as e:
			last_err = e
			log(f"⚠️ Upload attempt {attempt}/{UPLOAD_RETRIES} network error:", e)
		if attempt < UPLOAD_RETRIES:
			time.sleep(UPLOAD_RETRY_SLEEP_SEC)

	raise BackendUploadError(
		f"upload failed after {UPLOAD_RETRIES} attempts: {last_err}"
	)


def try_upload_pending(file_name: str) -> bool:
	"""If a prior run left an unacked transcript, POST it again. True = done."""
	pending = load_pending_result(file_name)
	if pending is None:
		return False
	text = str(pending.get("result") or "")
	pt = pending.get("PLAYTIME")
	vol = pending.get("VOLUME")
	playtime = float(pt) if pt is not None and pt != "" else None
	volume = float(vol) if vol is not None and vol != "" else None
	log(f"♻️  Повторная отправка сохранённого текста для {file_name} (без Whisper)")
	upload_transcription(file_name, text, playtime=playtime, volume=volume)
	return True

def upload_error_marker(
	file_name: str,
	err_msg: str,
	playtime: Optional[float] = None,
	volume: Optional[float] = None,
):
	"""Отправляем «служебную строку» об ошибке, чтобы файл вышел из очереди без повторов.
	PLAYTIME/VOLUME шлём и здесь — серверу не нужно потом самому оценивать аудио.
	Raises BackendUploadError if FreeSaaS does not confirm (caller must not mark done)."""
	# делаем однословную строку без переносов, ограничим длину
	clean = " ".join(err_msg.split())
	clean = clean[:300]
	marker = f" {clean}"
	upload_transcription(file_name, marker, playtime=playtime, volume=volume)

def post_to_whisper_server(audio_path: Path, temperature: Optional[float] = None) -> str:
	"""
	Шлём файл на whisper-server c output=srt и возвращаем сырой SRT-текст.
	temperature=None — брать значение из WS_FORM_PARAMS.
	"""
	form_data = dict(WS_FORM_PARAMS)
	if temperature is not None:
		form_data["temperature"] = str(temperature)

	with open(audio_path, "rb") as f:
		files = {"file": (audio_path.name, f, "audio/mpeg")}
		resp = requests.post(
			WHISPER_SERVER_URL,
			files=files,
			data=form_data,
			timeout=None,
		)
	if resp.status_code != 200:
		raise RuntimeError(f"whisper-server HTTP {resp.status_code}: {resp.text[:500]}")
	srt_text = resp.text.strip()
	err = whisper_response_to_error_if_any(srt_text)
	if err:
		raise RuntimeError(err)
	if "-->" not in srt_text:
		raise RuntimeError("Ответ сервера не похож на SRT (нет '-->'): первые 200 симв.\n" + srt_text[:200])
	return srt_text

def _request_temperature_value(temp: Optional[float]) -> float:
	if temp is not None:
		return float(temp)
	return float(WS_FORM_PARAMS["temperature"])

def post_to_whisper_server_with_empty_body_retry(
	audio_path: Path, temperature: Optional[float] = None
) -> str:
	"""
	Как post_to_whisper_server, но при пустом теле ответа повторяет запрос
	с теми же повышенными temperature, что и для прочих ретраев (только значения выше текущего).
	"""
	try:
		return post_to_whisper_server(audio_path, temperature=temperature)
	except RuntimeError as e:
		if str(e) != EMPTY_WHISPER_SERVER_RESPONSE:
			raise
	cur = _request_temperature_value(temperature)
	log(
		f"⚠️ {EMPTY_WHISPER_SERVER_RESPONSE} (temperature={cur}), "
		f"повтор с более высокой: {RETRY_TEMPERATURES_ON_EMPTY_RESPONSE}..."
	)
	for t in RETRY_TEMPERATURES_ON_EMPTY_RESPONSE:
		if t <= cur:
			continue
		try:
			return post_to_whisper_server(audio_path, temperature=t)
		except RuntimeError as e2:
			if str(e2) != EMPTY_WHISPER_SERVER_RESPONSE:
				raise
			cur = t
			continue
	raise RuntimeError(EMPTY_WHISPER_SERVER_RESPONSE)

def transcribe_upload_srt(
	audio_path: Path,
	file_name: str,
	playtime: Optional[float] = None,
	volume: Optional[float] = None,
):
	"""
	whisper-server → кастомный формат.
	Вторые прогоны только если после первого: мусор и/или не больше одной строки сегмента.
	Ретраи идут по возрастанию temperature; как только сегментов > 1 и мусора нет — стоп (экономия CPU).
	Если за все попытки условие не выполнено — остаётся лучший по transcription_quality_key.
	"""
	def run_once(temp: Optional[float]) -> str:
		srt_text = post_to_whisper_server_with_empty_body_retry(audio_path, temperature=temp)
		formatted = srt_text_to_custom(srt_text)
		if not formatted.strip():
			raise RuntimeError("пустая транскрипция после парсинга SRT")
		return formatted

	def quality_ok(s: str) -> bool:
		return (not transcription_looks_like_script_garbage(s)) and (
			custom_format_nonempty_line_count(s) > 1
		)

	formatted = run_once(None)
	best = formatted

	if quality_ok(formatted):
		upload_transcription(file_name, best, playtime=playtime, volume=volume)
		return

	retry_temps: list[float] = []
	if transcription_looks_like_script_garbage(formatted):
		n0, cjk0, r0 = transcription_garbage_metrics(formatted)
		log(
			f"⚠️ Транскрипция похожа на мусор (CJK-подобные: {cjk0}/{n0}, доля {r0:.2%}), "
			f"повторы с temperature {RETRY_TEMPERATURES_ON_GARBAGE} (по возрастанию)..."
		)
		retry_temps.extend(RETRY_TEMPERATURES_ON_GARBAGE)
	if custom_format_nonempty_line_count(formatted) <= 1:
		log(
			f"⚠️ В результате только одна строка сегмента — повторы с temperature "
			f"{RETRY_TEMPERATURES_ON_GARBAGE}; остановимся, как только будет >1 строки без мусора."
		)
		retry_temps.extend(RETRY_TEMPERATURES_ON_GARBAGE)

	unique_temps = list(dict.fromkeys(retry_temps))
	for t in unique_temps:
		try:
			cand = run_once(t)
		except Exception as e:
			# Падение локального сервера не глотаем — пусть всплывёт в main().
			if is_whisper_server_unreachable_error(e):
				raise
			log(f"⚠️ Повтор при temperature={t} не удался:", e)
			continue
		if quality_ok(cand):
			best = cand
			log(
				f"✓ Достаточно качества при temperature={t} (>1 сегмента, без мусора) — "
				f"дальнейшие прогоны не делаем."
			)
			break
		best = pick_better_transcription(best, cand)

	if unique_temps:
		if not quality_ok(best):
			if transcription_looks_like_script_garbage(best):
				log("⚠️ После повторов текст всё ещё похож на мусор — отправляем лучший по метрике среди прогонов.")
			elif custom_format_nonempty_line_count(best) <= 1:
				log("ℹ️ После повторов по-прежнему одна строка — отправляем лучший из доступных прогонов.")

	upload_transcription(file_name, best, playtime=playtime, volume=volume)

# ---------- Аналитика ----------

def run_analytics() -> bool:
	"""
	POST stem enqueue (HTTP 202). Cron drains stem/collect/publish.
	Returns True if this idle slot should count as a completed analytics attempt.
	HTTP 409 blocked_run on /run = lock busy — retry later, do not burn max_runs_per_idle.
	"""
	# Day-scoped stem keeps collect in today's window (avoids full-table orphan DELETE / 1317).
	day = datetime.now().strftime("%Y-%m-%d")
	url = ANALYTICS_URL
	sep = "&" if ("?" in url) else "?"
	url = f"{url}{sep}day={day}"
	log(f"📊 Запускаем stem (аналитика): {url}")
	try:
		r = requests.post(
			url,
			headers=backend_headers(),
			verify=tls_verify(),
			timeout=600,
		)
		body = (r.text or "")[:240]
		log(f"📊 Stem завершился HTTP {r.status_code}: {body}")
		if r.status_code == 409:
			log("ℹ️ Stem занят (blocked_run) — предыдущий прогон ещё держит lock, слот не тратим.")
			return False
		return True
	except Exception as e:
		log("⚠️ Ошибка запуска stem:", e)
		return True

# ---------- Основной цикл ----------

def main():
	# состояние «окна простоя»
	idle_window_active = False
	analytics_runs_in_window = 0
	last_analytics_ts = None
	# сессия распознавания между idle-окнами
	session_processed = 0
	session_started_at: Optional[float] = None

	token_ok = bool(WORKER_TOKEN)
	token_mask = (
		(WORKER_TOKEN[:4] + "…" + WORKER_TOKEN[-4:])
		if token_ok and len(WORKER_TOKEN) > 12
		else ("set" if token_ok else "MISSING")
	)
	log(f"⚙️  SERVER={SERVER}")
	log(f"⚙️  FTP={FTPSERVER}")
	log(f"⚙️  claim={GET_FILE_URL}")
	log(f"⚙️  result={SAVE_RESULT_URL}")
	log(f"⚙️  stem={ANALYTICS_URL}")
	log(f"⚙️  X-Audio-Token={token_mask}")
	if not token_ok:
		log("⚠️ worker_token пуст — FreeSaaS вернёт 401/503 на claim/result/stem")
	if TLS_VERIFY is False:
		log("⚠️ TLS verify=OFF — X-Audio-Token уязвим к MITM; включите tls.verify или tls.ca_bundle")
	else:
		log(f"⚙️  TLS verify={TLS_VERIFY}")
	log(f"⚙️  whisper={WHISPER_SERVER_BASE} language={WS_FORM_PARAMS.get('language')}")
	log(
		f"⚙️  auto_novad_fallback={WHISPER_AUTO_NOVAD_FALLBACK} "
		f"start_script={WHISPER_START_SCRIPT.name} "
		f"health_wait={WHISPER_HEALTH_WAIT_SEC:.0f}s"
	)
	log(
		f"⚙️  Max notify: whisper_down={MAX_NOTIFY_WHISPER_DOWN} "
		f"summary={MAX_NOTIFY_RECOGNITION_SUMMARY} "
		f"novad_fallback={MAX_NOTIFY_NOVAD_FALLBACK} "
		f"chat={'yes' if MAX_CHAT_ID else 'no'}"
	)

	while True:
		try:
			log("📡 Проверка наличия файла на сервере...")
			r = requests.get(
				GET_FILE_URL,
				headers=backend_headers(),
				verify=tls_verify(),
				timeout=30,
			)
			r.raise_for_status()
			filename = r.text.strip()

			if filename == "None":
				# вошли/находимся в idle
				if not idle_window_active:
					# открываем новое «окно idle»
					idle_window_active = True
					analytics_runs_in_window = 0
					last_analytics_ts = None
					log("🟡 Началось окно простоя (idle). Готовимся к запуску аналитики.")

				# условия запуска аналитики (до 2 раз в это окно, с интервалом)
				now_ts = time.time()
				may_run = analytics_runs_in_window < ANALYTICS_MAX_RUNS_PER_IDLE
				interval_ok = (last_analytics_ts is None) or (now_ts - last_analytics_ts >= ANALYTICS_MIN_INTERVAL_SEC)

				if may_run and interval_ok:
					if (
						MAX_NOTIFY_RECOGNITION_SUMMARY
						and session_processed > 1
					):
						started = session_started_at or now_ts
						notify_max(
							"✅ Распознавание завершено<br>"
							f"Сервер: {SERVER}<br>"
							f"Файлов: {session_processed}<br>"
							f"Длительность: {format_duration(now_ts - started)}"
						)
						session_processed = 0
						session_started_at = None
					if run_analytics():
						analytics_runs_in_window += 1
						last_analytics_ts = now_ts
					else:
						# 409 busy: backoff with min_interval, keep remaining run quota
						last_analytics_ts = now_ts
				else:
					if not may_run:
						log("ℹ️ Лимит запусков аналитики в этом idle-окне исчерпан.")
					else:
						left = int(ANALYTICS_MIN_INTERVAL_SEC - (now_ts - last_analytics_ts))
						if left > 0:
							log(f"⏳ До следующего запуска аналитики: ~{left} сек.")

				# ночной сон
				now = datetime.now()
				if now.hour >= SLEEP_HOUR:
					sleep_mac()
					break

				log(f"😴 Файлов нет. Ждём {LOOP_SLEEP_SECONDS_IDLE} секунд...")
				time.sleep(LOOP_SLEEP_SECONDS_IDLE)
				continue

			# если файл есть — выходим из режима idle и сбрасываем окно
			if idle_window_active:
				log("🟢 Конец окна простоя (idle): появились файлы, сбрасываем счётчики аналитики.")
				idle_window_active = False
				analytics_runs_in_window = 0
				last_analytics_ts = None

			full_url = f"{FTPSERVER}{filename}"
			log(f"🎧 Получен файл: {filename}")

			file_done = False
			try:
				# Re-POST text left after a previous unacked save (no re-whisper).
				if try_upload_pending(filename):
					file_done = True
			except BackendUploadError as up_err:
				log("❌ Повтор pending не подтверждён:", up_err)
				# Keep pending on disk; skip Whisper this cycle.
				log(f"⏳ Цикл завершён, ждём {LOOP_SLEEP_SECONDS_RUN} сек...\n")
				time.sleep(LOOP_SLEEP_SECONDS_RUN)
				continue

			if file_done:
				if session_started_at is None:
					session_started_at = time.time()
				session_processed += 1
				log(f"⏳ Цикл завершён, ждём {LOOP_SLEEP_SECONDS_RUN} сек...\n")
				time.sleep(LOOP_SLEEP_SECONDS_RUN)
				continue

			with tempfile.TemporaryDirectory() as tmpdir:
				tmp_path = Path(tmpdir) / Path(filename).name
				download_file(full_url, tmp_path)
				playtime, volume = probe_audio_metadata(tmp_path)

				try:
					log("🧠 Отправляем в whisper-server (output=srt)...")
					transcribe_upload_srt(
						tmp_path, filename, playtime=playtime, volume=volume
					)
					file_done = True

				except BackendUploadError as up_err:
					# Текст уже есть в памяти/был отправлен, но FreeSaaS не подтвердил.
					# Не ставим file_done — claim TTL вернёт файл; pending остаётся на диске.
					log("❌ Сохранение результата не подтверждено:", up_err)
					continue

				except Exception as ws_err:
					# Локальный сервер лёг → novad-fallback или стоп клиента.
					if is_whisper_server_unreachable_error(ws_err):
						if WHISPER_AUTO_NOVAD_FALLBACK:
							outcome = recover_crash_with_novad(
								tmp_path,
								filename,
								playtime,
								volume,
								str(ws_err),
							)
							if outcome == "ok":
								file_done = True
							else:
								# transient: файл останется в очереди
								continue
						else:
							handle_whisper_server_down(ws_err)
							continue
					else:
						err_msg = str(ws_err)
						lower = err_msg.lower()
						# Как в voice_transcribe_poll: при ошибке чтения аудио — один retry через ffmpeg → mp3
						if "failed to read audio data" in lower or "failed to read" in lower:
							try:
								log("🔁 Retry: конвертация в mp3 и повтор whisper…")
								mp3_path = convert_audio_to_mp3(tmp_path)
								try:
									transcribe_upload_srt(
										mp3_path,
										filename,
										playtime=playtime,
										volume=volume,
									)
									file_done = True
								finally:
									try:
										mp3_path.unlink(missing_ok=True)
									except Exception:
										pass
							except Exception as retry_e:
								if is_whisper_server_unreachable_error(retry_e):
									if WHISPER_AUTO_NOVAD_FALLBACK:
										outcome = recover_crash_with_novad(
											tmp_path,
											filename,
											playtime,
											volume,
											str(retry_e),
										)
										if outcome == "ok":
											file_done = True
										else:
											continue
									else:
										handle_whisper_server_down(retry_e)
										continue
								else:
									log("❌ Ошибка whisper-server (retry mp3):", retry_e)
									upload_error_marker(
										filename, str(retry_e), playtime=playtime, volume=volume
									)
									file_done = True
						else:
							log("❌ Ошибка whisper-server:", ws_err)
							upload_error_marker(
								filename, err_msg, playtime=playtime, volume=volume
							)
							file_done = True

				if file_done:
					if session_started_at is None:
						session_started_at = time.time()
					session_processed += 1


		except Exception as e:
			log("❌ Ошибка:", e)

		log(f"⏳ Цикл завершён, ждём {LOOP_SLEEP_SECONDS_RUN} сек...\n")
		time.sleep(LOOP_SLEEP_SECONDS_RUN)

if __name__ == "__main__":
	args = parse_args()
	cfg_path = args.config.expanduser().resolve()
	if not cfg_path.is_file():
		raise SystemExit(f"Конфиг не найден: {cfg_path}")
	load_config(cfg_path)
	log(f"📄 Конфиг клиента: {cfg_path}")
	main()
