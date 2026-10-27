#!/usr/bin/env bash
# Install Desktop + Applications shortcuts for Video Worker Status (macOS).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OPEN="$ROOT/scripts/status_open.sh"
chmod +x "$OPEN" "$ROOT/scripts/install_status_shortcut.sh" 2>/dev/null || true
chmod +x "$ROOT/status_dashboard.py" "$ROOT/Video Worker Status.command" 2>/dev/null || true

# Keep repo .command pointing at this checkout
cat >"$ROOT/Video Worker Status.command" <<EOF
#!/bin/bash
exec "$OPEN"
EOF
chmod +x "$ROOT/Video Worker Status.command"

DESKTOP="${HOME}/Desktop"
APPS="${HOME}/Applications"
mkdir -p "$APPS"

cp -f "$ROOT/Video Worker Status.command" "$DESKTOP/Video Worker Status.command"
cp -f "$ROOT/Video Worker Status.command" "$APPS/Video Worker Status.command"
chmod +x "$DESKTOP/Video Worker Status.command" "$APPS/Video Worker Status.command"

# Minimal .app (Dock / Launchpad)
APP_DIR="$APPS/Video Worker Status.app"
rm -rf "$APP_DIR"
mkdir -p "$APP_DIR/Contents/MacOS"
cat >"$APP_DIR/Contents/Info.plist" <<'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleExecutable</key>
  <string>VideoWorkerStatus</string>
  <key>CFBundleIdentifier</key>
  <string>ru.likespa.video-worker-status</string>
  <key>CFBundleName</key>
  <string>Video Worker Status</string>
  <key>CFBundlePackageType</key>
  <string>APPL</string>
  <key>CFBundleVersion</key>
  <string>1.0</string>
</dict>
</plist>
PLIST
cat >"$APP_DIR/Contents/MacOS/VideoWorkerStatus" <<BIN
#!/bin/bash
exec "$OPEN"
BIN
chmod +x "$APP_DIR/Contents/MacOS/VideoWorkerStatus"

rm -rf "$DESKTOP/Video Worker Status.app"
cp -R "$APP_DIR" "$DESKTOP/Video Worker Status.app"

echo "Installed:"
echo "  $DESKTOP/Video Worker Status.app"
echo "  $APPS/Video Worker Status.app"
echo "  $DESKTOP/Video Worker Status.command"
echo "Double-click to open http://127.0.0.1:8766/"
