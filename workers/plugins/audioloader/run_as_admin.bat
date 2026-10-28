@echo off
REM GUI with elevation (badge card format). Prefer workers CLI for normal upload.
cd /d "%~dp0"
set "REPO=%~dp0..\..\.."
set "PYTHONPATH=%REPO%;%PYTHONPATH%"
powershell -NoProfile -Command "Start-Process -FilePath 'python' -ArgumentList '\"%~dp0worker.py\"','--gui' -Verb RunAs"
