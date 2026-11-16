#!/usr/bin/env bash
# Stop egisz OpenVPN daemon started by n3_vpn_up.sh.
set -euo pipefail

OVPN="${N3_OVPN:-}"
PID_FILE="${N3_VPN_PID:-}"

if [[ -z "$PID_FILE" && -n "$OVPN" ]]; then
  WORKDIR="$(cd "$(dirname "$OVPN")" && pwd)"
  BASE="$(basename "$OVPN" .ovpn)"
  PID_FILE="$WORKDIR/${BASE}.pid"
fi

if [[ -z "$PID_FILE" || ! -f "$PID_FILE" ]]; then
  echo "no pid file; nothing to stop"
  exit 0
fi

pid="$(cat "$PID_FILE" 2>/dev/null || true)"
if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
  kill "$pid" 2>/dev/null || sudo -n kill "$pid" 2>/dev/null || true
  sleep 0.5
  if kill -0 "$pid" 2>/dev/null; then
    kill -9 "$pid" 2>/dev/null || sudo -n kill -9 "$pid" 2>/dev/null || true
  fi
  echo "stopped openvpn pid=$pid"
else
  echo "stale pid file"
fi
rm -f "$PID_FILE"
