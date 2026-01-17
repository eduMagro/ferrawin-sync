@echo off
REM =====================================================================
REM  Stop Sync Listener
REM =====================================================================

cd /d "C:\xampp\htdocs\ferrawin-sync"

echo Deteniendo Sync Listener...

REM Verificar si existe archivo PID
if exist "listener.pid" (
    set /p PID=<listener.pid
    echo Terminando proceso PID: %PID%
    taskkill /PID %PID% /F 2>nul
    if %errorlevel%==0 (
        echo Listener detenido correctamente.
    ) else (
        echo El proceso ya no estaba corriendo.
    )
    del listener.pid 2>nul
) else (
    echo No se encontro archivo listener.pid
    echo Buscando procesos PHP con sync-listener...
    wmic process where "commandline like '%%sync-listener%%'" get processid,commandline 2>nul
)

echo.
echo Listo.
pause
