# Register / refresh FreeSaaSStackWatchdog (every 5 min) + FreeSaaSStack (on logon).
# Run once as Admin after copying start_freesaas_stack.ps1 to var\ (or point -StackScript).
param(
  [string]$StackScript = 'C:\freesaas\var\start_freesaas_stack.ps1',
  [string]$UserId = 'Admin'
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $StackScript)) {
  $fallback = Join-Path $PSScriptRoot 'start_freesaas_stack.ps1'
  if (Test-Path -LiteralPath $fallback) {
    $destDir = Split-Path -Parent $StackScript
    New-Item -ItemType Directory -Force -Path $destDir | Out-Null
    Copy-Item -LiteralPath $fallback -Destination $StackScript -Force
    Write-Host "copied template → $StackScript"
  } else {
    throw "missing stack script: $StackScript"
  }
}

$tr = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$StackScript`""
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$StackScript`""
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
$principal = New-ScheduledTaskPrincipal -UserId $UserId -LogonType S4U -RunLevel Highest

$wdTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)
Register-ScheduledTask -TaskName 'FreeSaaSStackWatchdog' -Action $action -Trigger $wdTrigger -Settings $settings -Principal $principal -Force | Out-Null
Write-Host 'FreeSaaSStackWatchdog: every 5 min'

$logon = New-ScheduledTaskTrigger -AtLogOn -User $UserId
Register-ScheduledTask -TaskName 'FreeSaaSStack' -Action $action -Trigger $logon -Settings $settings -Principal $principal -Force | Out-Null
Write-Host "FreeSaaSStack: at logon ($UserId)"
Write-Host "Task To Run: $tr"
