@echo off
REM =====================================================================
REM  Sync Listener - Inicia el cliente WebSocket para control remoto
REM =====================================================================

REM Obtener directorio del script
set "SCRIPT_DIR=%~dp0"
cd /d "%SCRIPT_DIR%"

echo =====================================================================
echo  FerraWin Sync Listener
echo =====================================================================
echo.
echo Iniciando cliente WebSocket para control remoto...
echo Presiona Ctrl+C para detener.
echo.

REM Buscar PHP en ubicaciones comunes
set "PHP_PATH="
if exist "C:\xampp\php\php.exe" set "PHP_PATH=C:\xampp\php\php.exe"
if exist "C:\php\php.exe" set "PHP_PATH=C:\php\php.exe"
if exist "%SCRIPT_DIR%php\php.exe" set "PHP_PATH=%SCRIPT_DIR%php\php.exe"

REM Si no se encontro, buscar en PATH
if "%PHP_PATH%"=="" (
    for %%i in (php.exe) do set "PHP_PATH=%%~$PATH:i"
)

if "%PHP_PATH%"=="" (
    echo ERROR: No se encontro PHP.
    echo Instala XAMPP o PHP y asegurate de que este en el PATH.
    pause
    exit /b 1
)

echo Usando PHP: %PHP_PATH%
echo.

REM Verificar que vendor existe
if not exist "vendor\autoload.php" (
    echo ERROR: No se encontraron las dependencias.
    echo Ejecuta: composer install
    pause
    exit /b 1
)

REM Ejecutar el listener
"%PHP_PATH%" sync-listener.php %*

echo.
echo Listener detenido.
pause
