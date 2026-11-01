#!/usr/bin/env bash
# Prefer FreeSaaS checkout as cwd; fall back to monorepo root from this script path.
set -euo pipefail
if [[ -f bin/freesaas.php && -f cfg/config.example.ini ]]; then
  ROOT="$PWD"
elif [[ -f "$(cd "$(dirname "$0")/../../../.." && pwd)/bin/freesaas.php" ]]; then
  ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
else
  echo "Run from a FreeSaaS repository root (bin/freesaas.php not found)." >&2
  exit 1
fi
cd "$ROOT"
if [[ ! -f cfg/config.ini ]]; then
  echo "missing cfg/config.ini — copy cfg/config.example.ini first" >&2
  exit 1
fi
php bin/freesaas.php doctor --format=json
php bin/freesaas.php health --format=json
