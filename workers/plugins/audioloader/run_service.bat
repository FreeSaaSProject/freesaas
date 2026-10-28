@echo off
REM Headless AudioLoader via FreeSaaS workers host (preferred) or direct worker.py
cd /d "%~dp0"
set "REPO=%~dp0..\..\.."
set "PYTHONPATH=%REPO%;%PYTHONPATH%"
if exist "%~dp0..\..\.venv\Scripts\pythonw.exe" (
  start "" "%~dp0..\..\.venv\Scripts\pythonw.exe" "%~dp0worker.py"
) else (
  start "" pythonw "%~dp0worker.py"
)
