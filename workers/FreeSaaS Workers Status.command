#!/bin/bash
# Double-click on Mac: open the localhost workers dashboard.
cd "$(dirname "$0")/.." || exit 1
export PYTHONPATH="${PWD}${PYTHONPATH:+:$PYTHONPATH}"
ROOT="$(cd workers && pwd)"
PY="${ROOT}/.venv/bin/python"
if [[ ! -x "$PY" ]]; then
  PY=python3
fi
exec "$PY" -m workers.core dashboard --open
