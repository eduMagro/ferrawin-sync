@echo off
setlocal enabledelayedexpansion

REM =====================================================================
REM  FerraWin Watchdog - Comprueba si el listener esta activo
REM  Se ejecuta via tarea programada cada 20 minutos
REM  Si el listener esta caido, lo relanza en segundo plano
REM =====================================================================

set "BASE_DIR=C:\xampp\htdocs\ferrawin-sync"
set "PID_FILE=%BASE_DIR%\listener.pid"
set "VBS_FILE=%BASE_DIR%\start-listener-background.vbs"

REM Timestamp y fecha locale-independent via PowerShell
for /f "usebackq" %%T in (`powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd HH:mm:ss'"`) do set "TS=%%T"
for /f "usebackq" %%D in (`powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd'"`) do set "TODAY=%%D"

set "LOG_FILE=%BASE_DIR%\logs\watchdog-%TODAY%.log"

REM Verificar que el VBS existe
if not exist "%VBS_FILE%" (
    echo [%TS%] [WATCHDOG] ERROR: No se encuentra %VBS_FILE% >> "%LOG_FILE%"
    goto :end
)

REM Si no existe listener.pid, el listener no esta corriendo
if not exist "%PID_FILE%" (
    echo [%TS%] [WATCHDOG] listener.pid no encontrado - relanzando >> "%LOG_FILE%"
    goto :launch
)

REM Leer PID del archivo
set /p LISTENER_PID=<"%PID_FILE%"

REM Verificar que el PID no este vacio
if "!LISTENER_PID!"=="" (
    echo [%TS%] [WATCHDOG] listener.pid esta vacio - relanzando >> "%LOG_FILE%"
    goto :launch
)

REM Comprobar si ese PID corresponde a un proceso php.exe activo
tasklist /FI "PID eq !LISTENER_PID!" /FO CSV 2>nul | findstr /I "php.exe" >nul
if %ERRORLEVEL% equ 0 (
    echo [%TS%] [WATCHDOG] OK - Listener activo (PID !LISTENER_PID!) >> "%LOG_FILE%"
    goto :end
)

echo [%TS%] [WATCHDOG] PID !LISTENER_PID! no encontrado en procesos - relanzando >> "%LOG_FILE%"

:launch
wscript.exe "%VBS_FILE%"
echo [%TS%] [WATCHDOG] Listener relanzado >> "%LOG_FILE%"

:end
endlocal
