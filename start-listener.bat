@echo off
REM =====================================================================
REM  Sync Listener - Inicia el cliente WebSocket para control remoto
REM =====================================================================
REM
REM  Este script inicia el sync-listener.php que escucha comandos
REM  de sincronizacion enviados desde produccion via Pusher.
REM
REM  Uso:
REM    start-listener.bat          - Iniciar en modo normal
REM    start-listener.bat --test   - Iniciar en modo prueba
REM
REM =====================================================================

cd /d "C:\xampp\htdocs\ferrawin-sync"

echo =====================================================================
echo  FerraWin Sync Listener
echo =====================================================================
echo.
echo Iniciando cliente WebSocket para control remoto...
echo Presiona Ctrl+C para detener.
echo.

REM Verificar que PHP existe
if not exist "C:\xampp\php\php.exe" (
    echo ERROR: No se encontro PHP en C:\xampp\php\php.exe
    pause
    exit /b 1
)

REM Verificar que vendor existe
if not exist "vendor\autoload.php" (
    echo ERROR: No se encontraron las dependencias.
    echo Ejecuta: composer install
    pause
    exit /b 1
)

REM Ejecutar el listener
"C:\xampp\php\php.exe" sync-listener.php %*

echo.
echo Listener detenido.
pause
