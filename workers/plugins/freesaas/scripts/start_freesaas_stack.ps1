# FreeSaaS web stack (php-cgi + Caddy) for Windows warm-standby PCs.
# Copy to <freesaas_root>/var/start_freesaas_stack.ps1 (instance-local; var/ is gitignored)
# or leave here — freesaas worker heal_stack falls back to this path.
#
# Task Scheduler:
#   FreeSaaSStack         — on logon → this script
#   FreeSaaSStackWatchdog — every 5 min → same (optional; worker heal also covers 502)
#
# Paths below match a typical C:\freesaas + C:\php + C:\caddy layout — edit if needed.
$ErrorActionPreference = 'Continue'
$freesaasRoot = if ($env:FREESAAS_ROOT) { $env:FREESAAS_ROOT } else { 'C:\freesaas' }
$phpCgi = if ($env:FREESAAS_PHP_CGI) { $env:FREESAAS_PHP_CGI } else { 'C:\php\php-cgi.exe' }
$phpDir = Split-Path -Parent $phpCgi
$caddyExe = if ($env:FREESAAS_CADDY) { $env:FREESAAS_CADDY } else { 'C:\caddy\caddy.exe' }
$caddyDir = Split-Path -Parent $caddyExe
$caddyfile = Join-Path $freesaasRoot 'var\Caddyfile'
$logDir = Join-Path $freesaasRoot 'var\log'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$log = Join-Path $logDir 'stack.log'
function Log([string]$m) {
  Add-Content -LiteralPath $log -Value ("{0} {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m)
}

Log 'stack ensure start'

# Kill leftover one-shot cmds that taskkill php-cgi/caddy then block on caddy run.
Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
  Where-Object {
    $_.CommandLine -and
    $_.CommandLine -match 'taskkill.*/IM php-cgi' -and
    $_.CommandLine -notmatch 'start_freesaas_stack'
  } |
  ForEach-Object {
    Log ("kill stale wrapper pid={0}" -f $_.ProcessId)
    Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
  }

# Win32_Process.Create — process outlives SSH/PowerShell parent (unlike start /B from a remote cmd).
$phpListen = Get-NetTCPConnection -LocalPort 9000 -State Listen -ErrorAction SilentlyContinue
if (-not $phpListen) {
  Get-Process php-cgi -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
  $null = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{
    CommandLine = "`"$phpCgi`" -b 127.0.0.1:9000"
    CurrentDirectory = $phpDir
  }
  Start-Sleep -Seconds 2
  $phpListen = Get-NetTCPConnection -LocalPort 9000 -State Listen -ErrorAction SilentlyContinue
  Log ("php-cgi listen={0}" -f [bool]$phpListen)
} else {
  Log ("php-cgi already pid={0}" -f $phpListen.OwningProcess)
}

$caddyListen = Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue
if (-not $caddyListen) {
  if (-not (Test-Path -LiteralPath $caddyfile)) {
    Log "missing Caddyfile: $caddyfile"
  } else {
    Get-Process caddy -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    $null = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{
      CommandLine = "`"$caddyExe`" run --config `"$caddyfile`" --adapter caddyfile"
      CurrentDirectory = $caddyDir
    }
    Start-Sleep -Seconds 2
    $caddyListen = Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue
    Log ("caddy listen={0}" -f [bool]$caddyListen)
  }
} else {
  Log ("caddy already pid={0}" -f $caddyListen.OwningProcess)
}

Log 'stack ensure done'
