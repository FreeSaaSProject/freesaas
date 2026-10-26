#!/usr/bin/env bash
# Bring up named macOS L2TP VPN, then force camera-only route (drop PPP default if present).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${VPN_ENV_FILE:-$HOME/video-tunnel/vpn.env}"
VPN_NAME="${VPN_NAME:-VPN VT}"
CAMERA_HOST="${CAMERA_HOST:-192.168.123.91}"

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a
  # shellcheck source=/dev/null
  source "$ENV_FILE"
  set +a
fi

VPN_NAME="${VPN_NAME:-VPN VT}"
CAMERA_HOST="${CAMERA_HOST:-192.168.123.91}"

status="$(scutil --nc status "$VPN_NAME" 2>/dev/null | head -1 || true)"
if [[ "$status" != "Connected" ]]; then
  if [[ -n "${VPN_USER:-}" && -n "${VPN_PASSWORD:-}" ]]; then
    args=(--nc start "$VPN_NAME" --user "$VPN_USER" --password "$VPN_PASSWORD")
    if [[ -n "${VPN_SECRET:-}" ]]; then
      args+=(--secret "$VPN_SECRET")
    fi
    scutil "${args[@]}"
  else
    scutil --nc start "$VPN_NAME"
  fi
  for _ in $(seq 1 30); do
    status="$(scutil --nc status "$VPN_NAME" 2>/dev/null | head -1 || true)"
    [[ "$status" == "Connected" ]] && break
    sleep 1
  done
fi

status="$(scutil --nc status "$VPN_NAME" 2>/dev/null | head -1 || true)"
if [[ "$status" != "Connected" ]]; then
  echo "VPN '$VPN_NAME' not connected (status=$status)" >&2
  exit 1
fi

# Prefer ppp interface that appeared after connect
PPP_IF=""
for i in $(seq 0 9); do
  if ifconfig "ppp$i" >/dev/null 2>&1; then
    PPP_IF="ppp$i"
  fi
done
if [[ -z "$PPP_IF" ]]; then
  echo "No ppp interface after VPN connect" >&2
  exit 1
fi

# Drop full-tunnel default via PPP if present; keep LAN default for FreeSaaS
while route -n get default 2>/dev/null | grep -q "interface: $PPP_IF"; do
  GW="$(route -n get default 2>/dev/null | awk '/gateway:/{print $2; exit}')"
  if [[ -n "$GW" ]]; then
    sudo -n route -n delete default "$GW" 2>/dev/null || route -n delete default "$GW" 2>/dev/null || true
  else
    break
  fi
done

# Host route only to the camera through PPP
if ! route -n get "$CAMERA_HOST" 2>/dev/null | grep -q "interface: $PPP_IF"; then
  # Point-to-point: gateway often equals peer; use -iface when needed
  PEER="$(ifconfig "$PPP_IF" 2>/dev/null | awk '/destination/{print $2; exit}')"
  if [[ -n "$PEER" ]]; then
    sudo -n route -n add -host "$CAMERA_HOST" "$PEER" 2>/dev/null \
      || route -n add -host "$CAMERA_HOST" "$PEER" 2>/dev/null \
      || sudo -n route -n add -host "$CAMERA_HOST" -interface "$PPP_IF" 2>/dev/null \
      || route -n add -host "$CAMERA_HOST" -interface "$PPP_IF" 2>/dev/null \
      || true
  else
    sudo -n route -n add -host "$CAMERA_HOST" -interface "$PPP_IF" 2>/dev/null \
      || route -n add -host "$CAMERA_HOST" -interface "$PPP_IF" 2>/dev/null \
      || true
  fi
fi

echo "VPN up via $PPP_IF; camera route -> $CAMERA_HOST"
ping -c 1 -W 2000 "$CAMERA_HOST" >/dev/null 2>&1 && echo "camera ping ok" || echo "camera ping failed (may still RTSP)"
