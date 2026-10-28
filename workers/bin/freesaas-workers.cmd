@echo off
REM FreeSaaS workers CLI (Windows). Run from anywhere.
set "ROOT=%~dp0.."
for %%I in ("%ROOT%") do set "ROOT=%%~fI"
for %%I in ("%ROOT%\..") do set "REPO=%%~fI"
set "PYTHONPATH=%REPO%;%PYTHONPATH%"
set "FREESAAS_WORKERS_ROOT=%ROOT%"
if exist "%ROOT%\.venv\Scripts\python.exe" (
  "%ROOT%\.venv\Scripts\python.exe" -m workers.core %*
) else (
  python -m workers.core %*
)
