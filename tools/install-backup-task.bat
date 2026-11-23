@echo off
REM Creates two automatic daily backups in Windows Task Scheduler (midday and evening).
REM RIGHT-CLICK this file and choose "Run as administrator".
net session >nul 2>&1
if errorlevel 1 ( echo Please right-click this file and choose "Run as administrator". & pause & exit /b 1 )

set RUN=%~dp0run-backup.bat

REM Remove the tasks made by earlier versions (they had another name), so backups do not run twice
schtasks /delete /f /tn "KibatsiBackup-Midday"  >nul 2>&1
schtasks /delete /f /tn "KibatsiBackup-Evening" >nul 2>&1

schtasks /create /f /tn "VCMS-Backup-Midday"  /tr "\"%RUN%\"" /sc daily /st 12:30 /ru SYSTEM /rl HIGHEST
schtasks /create /f /tn "VCMS-Backup-Evening" /tr "\"%RUN%\"" /sc daily /st 18:00 /ru SYSTEM /rl HIGHEST
REM If the computer was off at the scheduled time, run the backup as soon as it is back on
powershell -NoProfile -Command "foreach($n in 'VCMS-Backup-Midday','VCMS-Backup-Evening'){ $t=Get-ScheduledTask -TaskName $n; $t.Settings.StartWhenAvailable=$true; $t.Settings.DisallowStartIfOnBatteries=$false; $t.Settings.StopIfGoingOnBatteries=$false; Set-ScheduledTask -InputObject $t | Out-Null }"
echo.
echo Done. Testing one backup now...
schtasks /run /tn "VCMS-Backup-Midday"
echo Wait a minute, then look in the backup folder (see BACKUP_DIR in api\config.php) for a new .zip file.
pause
