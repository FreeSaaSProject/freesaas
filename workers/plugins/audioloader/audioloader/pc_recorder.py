"""
Запись с микрофона ПК: WAV или прямой MP3 через LAME stdin.
Сессия записи: сегменты подряд, выгрузка каждого файла в фоне.
"""
from __future__ import annotations

import logging
import os
import platform
import subprocess
import threading
import time
import wave
from datetime import datetime
from typing import TYPE_CHECKING, Callable, Optional

if TYPE_CHECKING:
    import configparser

logger = logging.getLogger("audioloader.pc_recorder")

_NUMPY_CPU_ERROR_HINT = (
    "NumPy несовместим с процессором этого ПК. "
    "Выполните: python -m pip install \"numpy>=1.24.0,<2.0.0\" --force-reinstall"
)


def _friendly_record_error(exc: BaseException) -> str:
    msg = str(exc)
    if "X86_V2" in msg or "baseline optimizations" in msg:
        return _NUMPY_CPU_ERROR_HINT
    return msg


def _import_sounddevice():
    import sounddevice as sd

    return sd


class PcRecorder:
    """Непрерывная сессия записи сегментами; выгрузка параллельно в фоне."""

    def __init__(
        self,
        cfg: "configparser.ConfigParser",
        dirs: dict,
        log: logging.Logger,
        on_state_change: Optional[Callable[[], None]] = None,
    ) -> None:
        self._cfg = cfg
        self._dirs = dirs
        self._log = log
        self._on_state_change = on_state_change
        self._lock = threading.Lock()
        self._recording = False
        self._session_active = False
        self._auto_mode = False
        self._pending_uploads = 0
        self._session_uploaded: list[str] = []
        self._session_failed: list[str] = []
        self._stop_event = threading.Event()
        self._thread: Optional[threading.Thread] = None
        self._stream = None
        self._lame_proc: Optional[subprocess.Popen] = None
        self._wav_file: Optional[wave.Wave_write] = None
        self._output_path = ""
        self._output_wav_path = ""
        self._segment_start: Optional[float] = None
        self._duration_sec = 0
        self._last_error = ""

    def is_recording(self) -> bool:
        with self._lock:
            return self._recording

    def is_session_active(self) -> bool:
        with self._lock:
            return self._session_active

    def is_processing(self) -> bool:
        """Фоновая выгрузка (для совместимости с планировщиком)."""
        return self.get_pending_uploads() > 0

    def get_pending_uploads(self) -> int:
        with self._lock:
            return self._pending_uploads

    def is_auto_mode(self) -> bool:
        with self._lock:
            return self._auto_mode

    def get_last_error(self) -> str:
        return self._last_error

    def get_progress(self) -> tuple[int, int]:
        with self._lock:
            if self._segment_start is None:
                return 0, self._duration_sec
            elapsed = int(time.time() - self._segment_start)
            return min(elapsed, self._duration_sec), self._duration_sec

    def start_session(self, auto: bool = False) -> bool:
        with self._lock:
            if self._recording:
                return False
            if self._session_active:
                return True
        try:
            from .audio_devices import list_input_devices, resolve_input_device
            from .config import (
                get_mic_device_index,
                get_mic_device_name,
                get_pc_record_duration_min,
                is_pc_recording_allowed_now,
            )

            if not list_input_devices():
                self._last_error = "Микрофон не обнаружен"
                self._notify()
                return False
            if not is_pc_recording_allowed_now(self._cfg):
                self._last_error = "Вне расписания записи"
                self._notify()
                return False

            device = resolve_input_device(get_mic_device_index(self._cfg), get_mic_device_name(self._cfg))
            if device is None:
                self._last_error = "Микрофон не обнаружен"
                self._notify()
                return False

            self._duration_sec = get_pc_record_duration_min(self._cfg) * 60
            with self._lock:
                self._session_active = True
                self._auto_mode = auto
                self._session_uploaded = []
                self._session_failed = []
                self._last_error = ""
            self._stop_event.clear()
            return self._start_segment_thread(device.index)
        except Exception as e:
            self._last_error = str(e)
            self._log.exception("Не удалось начать сессию записи")
            self._notify()
            return False

    def stop_session(self, reason: str = "manual") -> None:
        with self._lock:
            if not self._session_active and not self._recording:
                return
            self._session_active = False
        self._stop_event.set()
        self._log.info("Остановка сессии записи (%s)", reason)

    def start_segment(self, auto: bool = False) -> bool:
        """Совместимость: запуск сессии."""
        return self.start_session(auto=auto)

    def stop_segment(self, reason: str = "manual") -> None:
        """Совместимость: остановка сессии."""
        self.stop_session(reason=reason)

    def _notify(self) -> None:
        if self._on_state_change:
            try:
                self._on_state_change()
            except Exception:
                self._log.exception("on_state_change callback failed")

    def _should_continue_session(self) -> bool:
        from .config import is_pc_recording_allowed_now

        with self._lock:
            if not self._session_active:
                return False
        if self._stop_event.is_set():
            return False
        return is_pc_recording_allowed_now(self._cfg)

    def _start_segment_thread(self, device_index: int) -> bool:
        with self._lock:
            if self._recording:
                return False
            paths = build_output_paths(self._cfg, self._dirs)
            self._output_path = paths["mp3_path"]
            self._output_wav_path = paths["wav_path"]
            self._thread = threading.Thread(
                target=self._record_worker,
                args=(device_index,),
                daemon=True,
                name="pc-recorder",
            )
            self._recording = True
            self._segment_start = time.time()
            self._stop_event.clear()
            self._thread.start()
        self._notify()
        return True

    def _after_segment(self, output_mp3: str, output_wav: str) -> None:
        self._queue_upload(output_mp3, output_wav)
        if self._should_continue_session():
            from .audio_devices import resolve_input_device
            from .config import get_mic_device_index, get_mic_device_name

            device = resolve_input_device(get_mic_device_index(self._cfg), get_mic_device_name(self._cfg))
            if device is not None:
                self._start_segment_thread(device.index)
                return
            self._last_error = "Микрофон не обнаружен"
        with self._lock:
            self._session_active = False
        self._try_finalize_session()
        self._notify()

    def _queue_upload(self, mp3_path: str, wav_path: str) -> None:
        with self._lock:
            self._pending_uploads += 1

        def worker() -> None:
            try:
                ok, name = self._post_process_sync(mp3_path, wav_path)
                with self._lock:
                    if ok:
                        self._session_uploaded.append(name)
                    else:
                        self._session_failed.append(name)
            finally:
                with self._lock:
                    self._pending_uploads -= 1
                self._try_finalize_session()
                self._notify()

        threading.Thread(target=worker, daemon=True, name="pc-upload").start()

    def _try_finalize_session(self) -> None:
        with self._lock:
            if self._session_active or self._recording or self._pending_uploads > 0:
                return
            uploaded = list(self._session_uploaded)
            failed = list(self._session_failed)
            self._session_uploaded = []
            self._session_failed = []
        if not uploaded and not failed:
            return
        from .config import get_pc_upload_notify
        from .notify import send_notifications

        if get_pc_upload_notify(self._cfg) != "on_stop":
            return
        if uploaded and not failed:
            send_notifications(
                self._cfg,
                f"✅ Запись с микрофона завершена: выгружено {len(uploaded)} файл(ов).",
            )
        elif uploaded and failed:
            send_notifications(
                self._cfg,
                f"⚠️ Запись завершена: выгружено {len(uploaded)}, ошибок {len(failed)}.",
            )
        elif failed:
            send_notifications(self._cfg, f"❌ Запись завершена с ошибками выгрузки: {len(failed)} файл(ов).")

    def _record_worker(self, device_index: int) -> None:
        try:
            from .config import (
                get_lame_path_from_config,
                get_pc_channels,
                get_pc_mp3_quality,
                get_pc_record_direct_mp3,
                get_pc_sample_rate,
            )
            from .encode import build_lame_args

            sample_rate = get_pc_sample_rate(self._cfg)
            channels = get_pc_channels(self._cfg)
            direct_mp3 = get_pc_record_direct_mp3(self._cfg)
            lame_path = get_lame_path_from_config(self._cfg)
            if direct_mp3 and not os.path.isfile(lame_path):
                direct_mp3 = False

            sd = _import_sounddevice()
            blocksize = 1024
            dtype = "int16"

            if direct_mp3:
                lame_args = build_lame_args(
                    get_pc_mp3_quality(self._cfg),
                    sample_rate=sample_rate,
                    channels=channels,
                    stdin_mode=True,
                )
                creationflags = subprocess.CREATE_NO_WINDOW if platform.system() == "Windows" else 0
                os.makedirs(os.path.dirname(self._output_path), exist_ok=True)
                self._lame_proc = subprocess.Popen(
                    [lame_path] + lame_args + [self._output_path],
                    stdin=subprocess.PIPE,
                    stdout=subprocess.DEVNULL,
                    stderr=subprocess.PIPE,
                    creationflags=creationflags,
                )
                sink = self._lame_proc.stdin
            else:
                os.makedirs(os.path.dirname(self._output_wav_path), exist_ok=True)
                self._wav_file = wave.open(self._output_wav_path, "wb")
                self._wav_file.setnchannels(channels)
                self._wav_file.setsampwidth(2)
                self._wav_file.setframerate(sample_rate)
                sink = None

            def callback(indata, frames, time_info, status) -> None:
                if status:
                    self._log.debug("sounddevice status: %s", status)
                if self._stop_event.is_set():
                    raise sd.CallbackStop()
                if self._segment_start and time.time() - self._segment_start >= self._duration_sec:
                    raise sd.CallbackStop()
                pcm = indata.tobytes()
                if direct_mp3 and sink is not None:
                    sink.write(pcm)
                elif self._wav_file is not None:
                    self._wav_file.writeframes(pcm)

            with sd.InputStream(
                device=device_index,
                channels=channels,
                samplerate=sample_rate,
                dtype=dtype,
                blocksize=blocksize,
                callback=callback,
            ) as stream:
                self._stream = stream
                while not self._stop_event.is_set():
                    if self._segment_start and time.time() - self._segment_start >= self._duration_sec:
                        break
                    time.sleep(0.1)

        except Exception as e:
            if "CallbackStop" not in type(e).__name__:
                self._last_error = _friendly_record_error(e)
                self._log.exception("Ошибка записи с микрофона")
        finally:
            self._cleanup_stream()
            output_mp3 = self._output_path
            output_wav = self._output_wav_path
            with self._lock:
                self._recording = False
                self._segment_start = None
            self._notify()
            self._after_segment(output_mp3, output_wav)

    def _cleanup_stream(self) -> None:
        if self._wav_file is not None:
            try:
                self._wav_file.close()
            except Exception:
                pass
            self._wav_file = None
        if self._lame_proc is not None:
            proc = self._lame_proc
            self._lame_proc = None
            try:
                if proc.stdin:
                    proc.stdin.close()
            except Exception:
                pass
            try:
                proc.wait(timeout=30)
            except subprocess.TimeoutExpired:
                proc.kill()
            if proc.returncode not in (0, None):
                err = (proc.stderr.read() if proc.stderr else b"").decode("utf-8", errors="replace")
                if err:
                    self._log.warning("LAME stderr: %s", err)
                    if not self._last_error:
                        self._last_error = err.strip()
        self._stream = None

    def _post_process_sync(self, mp3_path: str, wav_path: str) -> tuple[bool, str]:
        from .config import get_pc_record_direct_mp3
        from .encode_ftp import run_post_record_pipeline

        direct = get_pc_record_direct_mp3(self._cfg)
        if direct and os.path.isfile(mp3_path) and os.path.getsize(mp3_path) > 0:
            return run_post_record_pipeline(self._cfg, self._dirs, self._log, mp3_path=mp3_path, wav_path=None)
        if os.path.isfile(wav_path) and os.path.getsize(wav_path) > 0:
            return run_post_record_pipeline(self._cfg, self._dirs, self._log, mp3_path=None, wav_path=wav_path)
        if direct and os.path.isfile(mp3_path):
            try:
                os.remove(mp3_path)
            except OSError:
                pass
        if os.path.isfile(wav_path):
            try:
                os.remove(wav_path)
            except OSError:
                pass
        name = os.path.basename(mp3_path or wav_path)
        if not self._last_error:
            self._last_error = "Файл записи пуст или не создан"
        return False, name


def build_output_paths(cfg: "configparser.ConfigParser", dirs: dict) -> dict:
    """Строит пути WAV/MP3 по шаблону имени."""
    from .config import get_mp3_name_template, get_pc_serial, get_pc_serial_auto
    from .encode import build_mp3_name_from_template
    from .pc_serial import resolve_pc_serial

    serial = resolve_pc_serial(get_pc_serial(cfg), get_pc_serial_auto(cfg))
    now = datetime.now()
    mp3_name = build_mp3_name_from_template(get_mp3_name_template(cfg), now, serial)
    base, _ = os.path.splitext(mp3_name)
    wav_name = base + ".wav"
    return {
        "mp3_path": os.path.join(dirs["mp3"], mp3_name),
        "wav_path": os.path.join(dirs["wav"], wav_name),
        "mp3_name": mp3_name,
        "serial": serial,
    }
