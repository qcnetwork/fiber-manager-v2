# Registers (or replaces) the Windows task that keeps the Laravel scheduler running for the signed-in user:
# hourly database backup check (backup:database --if-stale) and daily cleanup of old activity logs.
# Run once:  powershell -ExecutionPolicy Bypass -File scripts\windows\pasang-penjadwal.ps1
$ErrorActionPreference = 'Stop'
$name = 'AplikasiOdp Penjadwal'
$vbs = Join-Path $PSScriptRoot 'penjadwal-laravel.vbs'

$action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$vbs`""
$trigger = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings `
    -Description 'Penjadwal Laravel AplikasiOdp: backup database otomatis & pembersihan log' -Force | Out-Null
Start-ScheduledTask -TaskName $name
Write-Host "Task '$name' terpasang dan berjalan."
