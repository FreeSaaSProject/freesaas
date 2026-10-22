#!/usr/bin/env bash
# Nightly demo DB refresh: re-seed relative to "today" (no mysqldump required).
# Install on the host (not in web root), then crontab e.g.:
#   15 3 * * * /usr/local/bin/freesaas-demo-restore.sh demoru
#   20 3 * * * /usr/local/bin/freesaas-demo-restore.sh demoen
#
# Usage: freesaas-demo-restore.sh <demoru|demoen|path-to-instance>
set -euo pipefail

TARGET="${1:-}"
if [[ -z "$TARGET" ]]; then
  echo "Usage: $0 demoru|demoen|/path/to/instance" >&2
  exit 2
fi

case "$TARGET" in
  demoru|ru) ROOT="/Users/ilyakleshnin/Sites/demoru.freesaas.hm"; LOCALE=ru ;;
  demoen|en) ROOT="/Users/ilyakleshnin/Sites/demoen.freesaas.hm"; LOCALE=en ;;
  *)
    ROOT="$TARGET"
    LOCALE=""
    ;;
esac

if [[ ! -f "$ROOT/bin/freesaas.php" ]]; then
  echo "Not a FreeSaaS instance: $ROOT" >&2
  exit 1
fi

cd "$ROOT"
ARGS=(demo seed --reset --format=json)
if [[ -n "$LOCALE" ]]; then
  ARGS+=(--locale="$LOCALE")
fi

/usr/bin/env php bin/freesaas.php "${ARGS[@]}"
php bin/freesaas.php health --format=json >/dev/null || true
