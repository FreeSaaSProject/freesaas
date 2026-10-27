#!/usr/bin/env bash
# Idempotent: ensure local status dashboard is up, then open Safari/browser.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${VIDEO_STATUS_PORT:-8766}"
HOST="${VIDEO_STATUS_HOST:-127.0.0.1}"
URL="http://${HOST}:${PORT}/"
LOG_DIR="$ROOT/logs"
LOG="$LOG_DIR/status_dashboard.log"
PID_FILE="$LOG_DIR/status_dashboard.pid"
PY="${ROOT}/.venv/bin/python"
if [[ ! -x "$PY" ]]; then
  PY="$(command -v python3 || true)"
fi
if [[ -z "$PY" ]]; then
  echo "python3 not found" >&2
  exit 1
fi

mkdir -p "$LOG_DIR"

listening() {
  if command -v lsof >/dev/null 2>&1; then
    lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1
    return $?
  fi
  # fallback
  "$PY" - <<PY
import socket,sys
s=socket.socket(); s.settimeout(0.3)
try:
  s.connect(("${HOST}", ${PORT})); sys.exit(0)
except Exception:
  sys.exit(1)
PY
}

if ! listening; then
  # stale pid?
  if [[ -f "$PID_FILE" ]]; then
    old="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [[ -n "${old:-}" ]] && ! kill -0 "$old" 2>/dev/null; then
      rm -f "$PID_FILE"
    fi
  fi
  nohup "$PY" "$ROOT/status_dashboard.py" >>"$LOG" 2>&1 &
  echo $! >"$PID_FILE"
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    if listening; then
      break
    fi
    sleep 0.2
  done
fi

if command -v open >/dev/null 2>&1; then
  open "$URL"
else
  echo "$URL"
fi
