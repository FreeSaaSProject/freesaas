#!/usr/bin/env bash
# Tear down named macOS L2TP VPN used for camera RTSP.
set -euo pipefail

ENV_FILE="${VPN_ENV_FILE:-$HOME/video-tunnel/vpn.env}"
VPN_NAME="${VPN_NAME:-VPN VT}"
CAMERA_HOST="${CAMERA_HOST:-192.168.123.91}"

if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck source=/dev/null
  source "$ENV_FILE"
  set +a
fi

VPN_NAME="${VPN_NAME:-VPN VT}"
CAMERA_HOST="${CAMERA_HOST:-192.168.123.91}"

# Remove host route if we added one
route -n delete -host "$CAMERA_HOST" 2>/dev/null || true

status="$(scutil --nc status "$VPN_NAME" 2>/dev/null | head -1 || true)"
if [[ "$status" == "Connected" || "$status" == "Connecting" ]]; then
  scutil --nc stop "$VPN_NAME" || true
fi

echo "VPN '$VPN_NAME' stopped"
