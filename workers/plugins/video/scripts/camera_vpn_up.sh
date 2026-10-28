#!/usr/bin/env bash
# Bring up named macOS L2TP VPN, then force camera-only routing (no full-tunnel).
# L2TP "Send all traffic" installs a PPP default (gateway link#N) that `route delete`
# cannot remove — use `route change default <LAN_GW>` instead, then host-route the camera.
set -euo pipefail

ENV_FILE="${VPN_ENV_FILE:-$HOME/video-tunnel/vpn.env}"
VPN_NAME="${VPN_NAME:-VPN VT}"
CAMERA_HOST="${CAMERA_HOST:-192.168.123.91}"
ALLOW_FULL_TUNNEL="${ALLOW_FULL_TUNNEL:-0}"
LAN_GW_FALLBACK="${LAN_GW_FALLBACK:-192.168.1.1}"

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a
  # shellcheck source=/dev/null
  source "$ENV_FILE"
  set +a
fi

VPN_NAME="${VPN_NAME:-VPN VT}"
CAMERA_HOST="${CAMERA_HOST:-192.168.123.91}"

default_if() {
  route -n get default 2>/dev/null | awk '/interface:/{print $2; exit}' || true
}

default_gw() {
  route -n get default 2>/dev/null | awk '/gateway:/{print $2; exit}' || true
}

ppp_if_list() {
  for i in $(seq 0 9); do
    if ifconfig "ppp$i" >/dev/null 2>&1; then
      echo "ppp$i"
    fi
  done
}

fail_out() {
  local msg="$1"
  local code="${2:-2}"
  echo "ERROR: $msg" >&2
  if [[ "$ALLOW_FULL_TUNNEL" != "1" ]]; then
    scutil --nc stop "$VPN_NAME" || true
    sudo -n /sbin/route -n change default "$SAVED_GW" 2>/dev/null || true
    sudo -n /sbin/route -n add default "$SAVED_GW" 2>/dev/null || true
    echo "VPN stopped. Prefer: System Settings → VPN VT → uncheck «Send all traffic over VPN»." >&2
    exit "$code"
  fi
}

force_lan_default() {
  local gw="$1"
  local ifc="${2:-}"
  local tries=0
  while (( tries < 12 )); do
    tries=$((tries + 1))
    local cur
    cur="$(default_if)"
    if [[ -n "$cur" && "$cur" != ppp* ]]; then
      return 0
    fi
    echo "forcing LAN default via $gw (was if=${cur:-none}) try=$tries" >&2
    # Primary fix for macOS L2TP full-tunnel (PPP default uses link# gateway)
    sudo -n /sbin/route -n change default "$gw" 2>/dev/null || true
    if [[ -n "$ifc" && "$ifc" != ppp* ]]; then
      sudo -n /sbin/route -n change default "$gw" -ifscope "$ifc" 2>/dev/null || true
      sudo -n /sbin/route -n add default "$gw" -ifscope "$ifc" 2>/dev/null || true
    fi
    sudo -n /sbin/route -n add default "$gw" 2>/dev/null || true
    # Scoped PPP delete is a no-op for link# defaults, but harmless
    for p in $(ppp_if_list); do
      sudo -n /sbin/route -n delete default -ifscope "$p" 2>/dev/null || true
    done
    sleep 0.35
  done
  cur="$(default_if)"
  [[ -n "$cur" && "$cur" != ppp* ]]
}

add_camera_route() {
  local ppp_if="$1"
  local peer
  peer="$(ifconfig "$ppp_if" 2>/dev/null | awk '/destination/{print $2; exit}')"
  route -n delete -host "$CAMERA_HOST" 2>/dev/null || true
  if [[ -n "$peer" ]]; then
    sudo -n /sbin/route -n add -host "$CAMERA_HOST" "$peer" 2>/dev/null \
      || sudo -n /sbin/route -n add -host "$CAMERA_HOST" -interface "$ppp_if" 2>/dev/null \
      || return 1
  else
    sudo -n /sbin/route -n add -host "$CAMERA_HOST" -interface "$ppp_if" 2>/dev/null || return 1
  fi
  route -n get "$CAMERA_HOST" 2>/dev/null | grep -q "interface: $ppp_if"
}

SAVED_IF="$(default_if)"
SAVED_GW="$(default_gw)"
if [[ -z "$SAVED_IF" || "$SAVED_IF" == ppp* ]]; then
  SAVED_IF=""
  for cand in en0 en1 en2 bridge0; do
    if ifconfig "$cand" 2>/dev/null | grep -q "inet "; then
      SAVED_IF="$cand"
      break
    fi
  done
fi
if [[ -z "$SAVED_GW" || "$SAVED_GW" != 192.168.* ]]; then
  SAVED_GW="${LAN_GW:-$LAN_GW_FALLBACK}"
fi
echo "pre-VPN default: if=${SAVED_IF:-?} gw=${SAVED_GW:-?}" >&2

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

PPP_IF=""
for _ in $(seq 1 20); do
  PPP_IF="$(ppp_if_list | tail -1)"
  [[ -n "$PPP_IF" ]] && break
  sleep 0.3
done
[[ -n "$PPP_IF" ]] || fail_out "No ppp interface after VPN connect" 1

sleep 0.5
if ! force_lan_default "$SAVED_GW" "$SAVED_IF"; then
  fail_out "could not restore LAN default (still on $(default_if))" 2
fi

if ! add_camera_route "$PPP_IF"; then
  fail_out "camera host route to $CAMERA_HOST via $PPP_IF failed" 3
fi

DEF_IF="$(default_if)"
[[ -n "$DEF_IF" && "$DEF_IF" != ppp* ]] || fail_out "default still bad (if=${DEF_IF:-none})" 2

echo "VPN up via $PPP_IF; camera-only -> $CAMERA_HOST; default via $DEF_IF ($(default_gw))"
ping -c 1 -W 2000 "$CAMERA_HOST" >/dev/null 2>&1 && echo "camera ping ok" || echo "camera ping failed (may still RTSP)"
PUB="$(curl -sS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
if [[ -n "$PUB" ]]; then
  echo "public ip: $PUB"
  if [[ "$PUB" == "212.32.194.105" ]]; then
    fail_out "public IP still therms ($PUB) — full tunnel" 2
  fi
fi
