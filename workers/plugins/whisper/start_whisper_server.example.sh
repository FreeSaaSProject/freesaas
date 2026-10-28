#!/bin/bash
# Example launcher for whisper.cpp whisper-server (macOS / Linux).
# Copy to start_whisper_server.sh next to this file (or point whisper.start_script
# in configs/local.json). Adjust WHISPER_CPP_DIR / model paths for your host.
#
# Crash workaround: VAD can SIGSEGV on some builds —
#   WHISPER_VAD=0 ./start_whisper_server.sh
#
set -u

# Root of a built whisper.cpp tree (bin + models). Override via env.
WHISPER_CPP_DIR="${WHISPER_CPP_DIR:-$HOME/whisper.cpp}"
MODEL_DIR="${WHISPER_MODEL_DIR:-$WHISPER_CPP_DIR/models}"
LOG_DIR="${WHISPER_LOG_DIR:-$WHISPER_CPP_DIR/logs}"
SERVER_BIN="${WHISPER_SERVER_BIN:-$WHISPER_CPP_DIR/build/bin/whisper-server}"
HOST="${WHISPER_HOST:-127.0.0.1}"
PORT="${WHISPER_PORT:-17871}"

mkdir -p "$LOG_DIR"

# 1 = VAD on (default), 0 = VAD off
WHISPER_VAD="${WHISPER_VAD:-1}"

STAMP="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="$LOG_DIR/whisper-server-${STAMP}.log"
LATEST_LINK="$LOG_DIR/whisper-server-latest.log"
ln -sfn "$LOG_FILE" "$LATEST_LINK"

exec > >(tee -a "$LOG_FILE") 2>&1

echo "============================================================"
echo " whisper-server start  $(date '+%Y-%m-%d %H:%M:%S %z')"
echo " log: $LOG_FILE"
echo " host: ${HOST}:${PORT}"
echo " bin: $SERVER_BIN"
echo " WHISPER_VAD=${WHISPER_VAD}"
echo "============================================================"

if [[ ! -x "$SERVER_BIN" ]]; then
  echo "ERROR: whisper-server not found/executable: $SERVER_BIN" >&2
  echo "Build whisper.cpp or set WHISPER_CPP_DIR / WHISPER_SERVER_BIN." >&2
  exit 1
fi

COMMON_ARGS=(
  --host "$HOST"
  --port "$PORT"
  --model "$MODEL_DIR/ggml-large-v3.bin"
  --language ru
  --threads 8
  --beam-size 8
  --best-of 1
  --split-on-word
  --no-speech-thold 0.48
  --max-context 0
  --audio-ctx 0
  --entropy-thold 2.4
  --no-fallback
  --flash-attn
)

VAD_ARGS=(
  --vad
  --vad-model "$MODEL_DIR/ggml-silero-v5.1.2.bin"
  --vad-threshold 0.20
  --vad-min-speech-duration-ms 130
  --vad-min-silence-duration-ms 0
  --vad-speech-pad-ms 200
  --vad-samples-overlap 0.00
)

set +e
if [[ "$WHISPER_VAD" == "1" || "$WHISPER_VAD" == "true" || "$WHISPER_VAD" == "yes" ]]; then
  echo " mode: VAD enabled"
  "$SERVER_BIN" "${COMMON_ARGS[@]}" "${VAD_ARGS[@]}"
else
  echo " mode: VAD DISABLED"
  "$SERVER_BIN" "${COMMON_ARGS[@]}"
fi
RC=$?
echo ""
echo " whisper-server exited rc=$RC at $(date '+%Y-%m-%d %H:%M:%S %z')"
exit "$RC"
