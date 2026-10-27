#!/bin/bash
# Double-click on Mac: start status dashboard if needed and open the browser.
ROOT="$(cd "$(dirname "$0")" && pwd)"
exec "$ROOT/scripts/status_open.sh"
