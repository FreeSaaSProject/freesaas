#!/usr/bin/env bash
# Mirror this FreeSaaS tree to production FTP (put only — never --delete).
# Usage:
#   FREESAAS_FTP_USER=… FREESAAS_FTP_PASS=… [FREESAAS_FTP_HOST=freesaas.likespa.ru] bin/ftp-mirror.sh
# Optional: FREESAAS_FTP_PURGE_VENV=1 also tries to delete remote workers/.venv (slow).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${FREESAAS_FTP_HOST:-freesaas.likespa.ru}"
USER="${FREESAAS_FTP_USER:?set FREESAAS_FTP_USER}"
PASS="${FREESAAS_FTP_PASS:?set FREESAAS_FTP_PASS}"
SCRIPT="$(mktemp -t freesaas-ftp.XXXXXX.lftp)"
cleanup() { rm -f "$SCRIPT"; }
trap cleanup EXIT
PURGE=''
if [[ "${FREESAAS_FTP_PURGE_VENV:-0}" == "1" ]]; then
  PURGE=$(cat <<'P'
set cmd:fail-exit no
rm -r workers/.venv
rm -r workers/plugins/whisper/.venv
rm -r workers/plugins/video/.venv
set cmd:fail-exit yes
P
)
fi
cat > "$SCRIPT" << LFTP
set ftp:ssl-allow yes
set ssl:verify-certificate no
set net:timeout 60
set net:max-retries 3
set ftp:passive-mode true
set mirror:parallel-transfer-count 4
set xfer:clobber yes
open -u ${USER},${PASS} ftp://${HOST}
lcd ${ROOT}
cd /
${PURGE}
mirror -R --only-newer --verbose=1 \\
  -X .git -X .git/** \\
  -X .github -X .github/** \\
  -X dist -X dist/** \\
  -X var -X var/** \\
  -X log -X log/** \\
  -X .tmp -X .tmp/** \\
  -X .DS_Store \\
  -X .venv -X .venv/** \\
  -X workers/.venv -X workers/.venv/** \\
  -X workers/plugins/whisper/.venv -X workers/plugins/whisper/.venv/** \\
  -X workers/plugins/video/.venv -X workers/plugins/video/.venv/** \\
  -X workers/plugins/diarize/models -X workers/plugins/diarize/models/** \\
  -X workers/__pycache__ -X workers/__pycache__/** \\
  -X workers/plugins/diarize/__pycache__ -X workers/plugins/diarize/__pycache__/** \\
  -X workers/plugins/diarize/var -X workers/plugins/diarize/var/** \\
  -X workers/plugins/diarize/logs -X workers/plugins/diarize/logs/** \\
  -X cfg/config.ini \\
  -X cfg/updates.ini \\
  -X cfg/updates.state.json \\
  -X 'cfg/modules/*.ini' \\
  -X assets/uploads -X assets/uploads/** \\
  -X UserFiles -X UserFiles/** \\
  -X themes/likespa -X themes/likespa/** \\
  -X themes/balance -X themes/balance/** \\
  -X workers/var -X workers/var/** \\
  -X workers/host.local.json \\
  . .
echo --- remote VERSION ---
cat VERSION
cls -l modules/notify/module.json
cls -l modules/mail/module.json
bye
LFTP
lftp -f "$SCRIPT"
echo FTP_MIRROR_OK
