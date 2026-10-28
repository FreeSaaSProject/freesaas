@echo off
chcp 65001 >nul 2>&1
echo.
echo AudioLoader is a FreeSaaS workers plugin.
echo Preferred install from repo root:
echo   workers\bin\freesaas-workers.cmd install
echo   (enable "audioloader" in workers\host.local.json first)
echo.
echo Fallback: pip install -r requirements.txt into the workers venv.
echo LAME is NOT bundled — copy lame.exe here or onto PATH. See THIRD_PARTY.md
echo FFmpeg must be on PATH for Speechmate/CHINA3.
echo.
cd /d "%~dp0"
if exist "..\..\.venv\Scripts\python.exe" (
  "..\..\.venv\Scripts\python.exe" -m pip install -r requirements.txt
) else (
  python -m pip install -r requirements.txt
)
if not exist "lame.exe" (
  echo WARNING: lame.exe not found in this folder — obtain LAME separately.
)
pause
