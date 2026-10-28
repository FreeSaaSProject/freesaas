@echo off
REM Prefer: from repo root — python -m workers.core autostart on
REM This bat only enables the legacy per-plugin Task Scheduler entry.
cd /d "%~dp0"
set "REPO=%~dp0..\..\.."
set "PYTHONPATH=%REPO%;%PYTHONPATH%"
python -c "from audioloader.autostart import enable_autostart; ok,m=enable_autostart(); print(m); raise SystemExit(0 if ok else 1)"
