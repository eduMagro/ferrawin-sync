@echo off
schtasks /create /tn "FerrawinSync" /tr "C:\xampp\htdocs\ferrawin-sync\sync-ferrawin.bat" /sc daily /st 14:00 /f
if %ERRORLEVEL% EQU 0 (
    echo Tarea creada exitosamente
    schtasks /query /tn "FerrawinSync" /v /fo LIST
) else (
    echo Error al crear la tarea. Ejecuta como Administrador.
)
pause
