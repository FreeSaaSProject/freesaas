#!/usr/bin/env bash
# OpenVPN split-tunnel for N3 demo: ignore redirect-gateway, accept pushed host routes.
set -euo pipefail

OVPN="${N3_OVPN:-}"
PID_FILE="${N3_VPN_PID:-}"
LOG_FILE="${N3_VPN_LOG:-}"
OPEN_VPN_BIN="${OPENVPN_BIN:-}"

if [[ -z "$OVPN" || ! -f "$OVPN" ]]; then
  echo "ERROR: N3_OVPN missing or not a file: ${OVPN:-}" >&2
  exit 1
fi

if [[ -z "$OPEN_VPN_BIN" ]]; then
  for c in openvpn /opt/homebrew/sbin/openvpn /usr/local/sbin/openvpn; do
    if [[ -x "$c" ]] || command -v "$c" >/dev/null 2>&1; then
      OPEN_VPN_BIN="$(command -v "$c" 2>/dev/null || echo "$c")"
      break
    fi
  done
fi
[[ -n "$OPEN_VPN_BIN" && -x "$OPEN_VPN_BIN" ]] || { echo "ERROR: openvpn not found" >&2; exit 1; }

WORKDIR="$(cd "$(dirname "$OVPN")" && pwd)"
BASE="$(basename "$OVPN" .ovpn)"
PID_FILE="${PID_FILE:-$WORKDIR/${BASE}.pid}"
LOG_FILE="${LOG_FILE:-$WORKDIR/${BASE}.log}"
RUNTIME_CFG="${N3_VPN_RUNTIME:-/tmp/egisz-n3-demo.ovpn}"
PID_FILE="${N3_VPN_PID:-/tmp/egisz-n3-demo.pid}"
LOG_FILE="${N3_VPN_LOG:-/tmp/egisz-n3-demo.log}"

if [[ -f "$PID_FILE" ]]; then
  old="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [[ -n "$old" ]] && kill -0 "$old" 2>/dev/null; then
    echo "openvpn already running pid=$old"
    exit 0
  fi
fi

{
  cat "$OVPN"
  echo
  echo "# FreeSaaS egisz split-tunnel"
  echo "pull-filter ignore redirect-gateway"
  echo "data-ciphers AES-256-CBC:AES-256-GCM:AES-128-GCM"
  echo "cipher AES-256-CBC"
} > "$RUNTIME_CFG"
chmod 644 "$RUNTIME_CFG"

run_ovpn() {
  "$OPEN_VPN_BIN" --config "$RUNTIME_CFG" --daemon --writepid "$PID_FILE" --log "$LOG_FILE"
}

if ! run_ovpn 2>/dev/null; then
  if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
    sudo -n "$OPEN_VPN_BIN" --config "$RUNTIME_CFG" --daemon --writepid "$PID_FILE" --log "$LOG_FILE"
  else
    echo "ERROR: openvpn needs privileges (sudo -n or run as root)" >&2
    exit 2
  fi
fi

for _ in $(seq 1 40); do
  if grep -q "Initialization Sequence Completed\|Data Channel:" "$LOG_FILE" 2>/dev/null \
     || grep -q "Data Channel:" "$LOG_FILE" 2>/dev/null; then
    break
  fi
  if grep -q "AUTH_FAILED\|SIGTERM" "$LOG_FILE" 2>/dev/null; then
    echo "ERROR: openvpn failed; see $LOG_FILE" >&2
    exit 3
  fi
  sleep 0.25
done

echo "VPN up pid=$(cat "$PID_FILE" 2>/dev/null || echo ?) log=$LOG_FILE"
