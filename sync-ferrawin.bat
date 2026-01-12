@echo off
REM =====================================================================
REM FerraWin Sync - Script para Windows Task Scheduler
REM Ejecuta la sincronización diaria a las 14:00
REM =====================================================================

REM Configuración
set PHP_PATH=C:\xampp\php\php.exe
set SCRIPT_PATH=C:\xampp\htdocs\ferrawin-sync\sync.php

REM Cambiar al directorio del proyecto
cd /d C:\xampp\htdocs\ferrawin-sync

REM Ejecutar sincronización
echo [%date% %time%] Iniciando sincronización FerraWin...
"%PHP_PATH%" "%SCRIPT_PATH%"

REM Capturar código de salida
set EXIT_CODE=%ERRORLEVEL%

if %EXIT_CODE% EQU 0 (
    echo [%date% %time%] Sincronización completada exitosamente.
) else (
    echo [%date% %time%] Error en la sincronización. Código: %EXIT_CODE%
)

exit /b %EXIT_CODE%
