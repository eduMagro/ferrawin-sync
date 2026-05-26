# =====================================================================
#  FerraWin Sync - Instalar todas las tareas programadas
# =====================================================================
#
#  Ejecutar como Administrador:
#    powershell -ExecutionPolicy Bypass -File install-tasks.ps1
#
#  Instala dos tareas:
#    1. FerrawinSyncListener  - arranca el listener al iniciar Windows
#    2. FerrawinWatchdog      - comprueba cada 20 min y relanza si cayo
#
# =====================================================================

$baseDir = "C:\xampp\htdocs\ferrawin-sync"
$vbsPath = "$baseDir\start-listener-background.vbs"
$batPath = "$baseDir\watchdog.bat"

$principal = New-ScheduledTaskPrincipal `
    -UserId $env:USERNAME `
    -RunLevel Highest `
    -LogonType S4U

function Install-Task {
    param($name, $action, $trigger, $settings, $description)

    $existing = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    if ($existing) {
        Write-Host "  Eliminando tarea existente '$name'..." -ForegroundColor Yellow
        Unregister-ScheduledTask -TaskName $name -Confirm:$false
    }

    Register-ScheduledTask `
        -TaskName   $name `
        -Action     $action `
        -Trigger    $trigger `
        -Settings   $settings `
        -Principal  $principal `
        -Description $description | Out-Null

    Write-Host "  OK: $name" -ForegroundColor Green
}

# -----------------------------------------------------------------------
# Tarea 1: FerrawinSyncListener — arranca el listener al iniciar Windows
# -----------------------------------------------------------------------
Write-Host ""
Write-Host "[1/2] FerrawinSyncListener" -ForegroundColor Cyan

Install-Task `
    -Name        "FerrawinSyncListener" `
    -Action      (New-ScheduledTaskAction -Execute "wscript.exe" -Argument "`"$vbsPath`"") `
    -Trigger     (New-ScheduledTaskTrigger -AtStartup) `
    -Settings    (New-ScheduledTaskSettingsSet `
                    -AllowStartIfOnBatteries `
                    -DontStopIfGoingOnBatteries `
                    -StartWhenAvailable `
                    -ExecutionTimeLimit (New-TimeSpan -Seconds 0)) `
    -Description "Arranca el listener FerraWin al iniciar Windows"

# -----------------------------------------------------------------------
# Tarea 2: FerrawinWatchdog — comprueba cada 20 min y relanza si cayo
# -----------------------------------------------------------------------
Write-Host ""
Write-Host "[2/2] FerrawinWatchdog" -ForegroundColor Cyan

Install-Task `
    -Name        "FerrawinWatchdog" `
    -Action      (New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$batPath`"") `
    -Trigger     (New-ScheduledTaskTrigger -Once -At "00:00" -RepetitionInterval (New-TimeSpan -Minutes 20)) `
    -Settings    (New-ScheduledTaskSettingsSet `
                    -AllowStartIfOnBatteries `
                    -DontStopIfGoingOnBatteries `
                    -StartWhenAvailable `
                    -ExecutionTimeLimit (New-TimeSpan -Minutes 3)) `
    -Description "Watchdog: comprueba cada 20 minutos si el listener esta activo y lo relanza si cayo"

# -----------------------------------------------------------------------
# Resumen
# -----------------------------------------------------------------------
Write-Host ""
Write-Host "====================================================" -ForegroundColor White
Write-Host " Tareas instaladas correctamente" -ForegroundColor Green
Write-Host "====================================================" -ForegroundColor White
Write-Host ""
Write-Host " FerrawinSyncListener  -> al arrancar Windows" -ForegroundColor White
Write-Host " FerrawinWatchdog      -> cada 20 minutos" -ForegroundColor White
Write-Host ""
Write-Host "Logs del watchdog:" -ForegroundColor Yellow
$today = Get-Date -Format "yyyy-MM-dd"
Write-Host "  $baseDir\logs\watchdog-$today.log" -ForegroundColor Gray
Write-Host ""
Write-Host "Lanzar listener ahora:" -ForegroundColor Yellow
Write-Host "  schtasks /run /tn ""FerrawinSyncListener""" -ForegroundColor Gray
Write-Host ""
Write-Host "Verificar estado de las tareas:" -ForegroundColor Yellow
Write-Host "  schtasks /query /tn ""FerrawinSyncListener"" /fo LIST" -ForegroundColor Gray
Write-Host "  schtasks /query /tn ""FerrawinWatchdog"" /fo LIST" -ForegroundColor Gray
Write-Host ""
