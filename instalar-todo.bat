@echo off
setlocal EnableDelayedExpansion

echo.
echo =====================================================================
echo  INSTALADOR AUTOMATICO - FerraWin Sync
echo =====================================================================
echo.
echo Este script instalara automaticamente:
echo   - ODBC Driver 18 para SQL Server (si no esta instalado)
echo   - PHP 8.2 (portable)
echo   - Extensiones SQL Server (sqlsrv + pdo_sqlsrv)
echo   - Certificados SSL (cacert.pem)
echo   - Composer
echo   - Dependencias del proyecto
echo.
echo =====================================================================
echo.

set "SCRIPT_DIR=%~dp0"
set "INSTALL_DIR=%SCRIPT_DIR%php"
set "PHP_URL="

echo =====================================================================
echo  PASO 0: Verificando requisitos del sistema
echo =====================================================================
echo.

REM Verificar ODBC Driver for SQL Server
set "ODBC_OK=0"
set "ODBC_MSI=%TEMP%\msodbcsql18_x64.msi"
reg query "HKLM\SOFTWARE\ODBC\ODBCINST.INI\ODBC Driver 18 for SQL Server" >nul 2>&1
if %ERRORLEVEL% equ 0 set "ODBC_OK=1"
if "!ODBC_OK!"=="0" (
    reg query "HKLM\SOFTWARE\ODBC\ODBCINST.INI\ODBC Driver 17 for SQL Server" >nul 2>&1
    if !ERRORLEVEL! equ 0 set "ODBC_OK=1"
)

if "!ODBC_OK!"=="1" (
    echo [OK] Microsoft ODBC Driver for SQL Server encontrado
) else (
    echo [INFO] ODBC Driver for SQL Server no encontrado.
    echo [INFO] Descargando e instalando automaticamente ODBC Driver 18...
    echo.

    net session >nul 2>&1
    if !ERRORLEVEL! neq 0 (
        echo [ERROR] Se requieren permisos de Administrador para instalar ODBC Driver.
        echo         Cierra esta ventana, haz clic derecho sobre instalar-todo.bat
        echo         y selecciona "Ejecutar como administrador".
        echo.
        pause
        exit /b 1
    )

    echo Descargando ODBC Driver 18 para SQL Server ^(x64, ~8 MB^)...
    powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://go.microsoft.com/fwlink/?linkid=2249004' -OutFile '%ODBC_MSI%' -UseBasicParsing"

    if not exist "%ODBC_MSI%" (
        echo [ERROR] No se pudo descargar ODBC Driver 18.
        echo         Descarga e instala manualmente desde:
        echo         https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server
        echo.
        pause
        exit /b 1
    )

    echo Instalando ODBC Driver 18 ^(proceso silencioso, puede tardar 1-2 min^)...
    msiexec /i "%ODBC_MSI%" /quiet /norestart IACCEPTMSODBCSQLLICENSETERMS=YES
    timeout /t 5 /nobreak >nul

    reg query "HKLM\SOFTWARE\ODBC\ODBCINST.INI\ODBC Driver 18 for SQL Server" >nul 2>&1
    if !ERRORLEVEL! equ 0 (
        echo [OK] ODBC Driver 18 instalado correctamente
    ) else (
        echo [AVISO] No se pudo verificar la instalacion del ODBC Driver.
        echo         Si hay errores al conectar a SQL Server, instala manualmente y re-ejecuta.
        echo.
    )
)

REM Verificar Visual C++ Redistributable
if exist "C:\Windows\System32\vcruntime140.dll" (
    echo [OK] Visual C++ Redistributable presente
) else (
    echo [AVISO] vcruntime140.dll no encontrado. Descarga Visual C++ Redistributable 2022 x64:
    echo         https://aka.ms/vs/17/release/vc_redist.x64.exe
    echo.
)
echo.
set "COMPOSER_URL=https://getcomposer.org/download/latest-stable/composer.phar"

REM Verificar si ya existe PHP en el proyecto
if exist "%INSTALL_DIR%\php.exe" (
    echo [INFO] PHP ya esta instalado en %INSTALL_DIR%
    set "PHP_PATH=%INSTALL_DIR%\php.exe"
    goto :check_extensions
)

REM Verificar si existe PHP en C:\php
if exist "C:\php\php.exe" (
    echo [INFO] PHP encontrado en C:\php
    set "PHP_PATH=C:\php\php.exe"
    set "INSTALL_DIR=C:\php"
    goto :check_extensions
)

REM Verificar si existe XAMPP
if exist "C:\xampp\php\php.exe" (
    echo [INFO] PHP encontrado en XAMPP
    set "PHP_PATH=C:\xampp\php\php.exe"
    set "INSTALL_DIR=C:\xampp\php"
    goto :check_extensions
)

echo =====================================================================
echo  PASO 1: Instalando PHP 8.2
echo =====================================================================
echo.

REM Crear directorio
if not exist "%INSTALL_DIR%" mkdir "%INSTALL_DIR%"

echo Detectando version actual de PHP 8.2 en windows.php.net...
powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; try { $page = (Invoke-WebRequest -Uri 'https://windows.php.net/downloads/releases/' -UseBasicParsing).Content; $m = [regex]::Matches($page, 'php-(8\.2\.\d+)-Win32-vs16-x64\.zip'); if ($m.Count -gt 0) { $ver = ($m | Sort-Object { [version]$_.Groups[1].Value } -Descending | Select-Object -First 1).Groups[1].Value; [IO.File]::WriteAllText('%TEMP%\phpver.txt', $ver) } else { [IO.File]::WriteAllText('%TEMP%\phpver.txt', 'NOT_FOUND') } } catch { [IO.File]::WriteAllText('%TEMP%\phpver.txt', 'ERROR') }"
set /p PHP_VER=<"%TEMP%\phpver.txt"
REM Eliminar posible \r al final (Out-File escribe CRLF)
set "PHP_VER=%PHP_VER%"
echo Version detectada: %PHP_VER%

if "%PHP_VER%"=="" goto :php_manual
if "%PHP_VER%"=="NOT_FOUND" goto :php_manual
if "%PHP_VER%"=="ERROR" goto :php_manual

set "PHP_URL=https://windows.php.net/downloads/releases/php-%PHP_VER%-Win32-vs16-x64.zip"
echo Descargando PHP %PHP_VER%...
echo URL: %PHP_URL%
powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%PHP_URL%' -OutFile '%TEMP%\php.zip' -UseBasicParsing"
goto :php_downloaded

:php_manual

    echo [ERROR] No se pudo detectar la version de PHP automaticamente.
    echo.
    echo Descarga manualmente desde: https://windows.php.net/download/
    echo Elige: PHP 8.2 - VS16 x64 Thread Safe - Zip
    echo Extrae en: %INSTALL_DIR%
    echo.
    pause
    exit /b 1

:php_downloaded
if not exist "%TEMP%\php.zip" (
    echo [ERROR] No se pudo descargar PHP
    echo.
    echo Descarga manualmente desde: https://windows.php.net/download/
    echo Elige: PHP 8.2 - VS16 x64 Thread Safe - Zip
    echo Extrae en: %INSTALL_DIR%
    echo.
    pause
    exit /b 1
)

echo Extrayendo PHP en %INSTALL_DIR%...
powershell -Command "Expand-Archive -Path '%TEMP%\php.zip' -DestinationPath '%INSTALL_DIR%' -Force"

if not exist "%INSTALL_DIR%\php.exe" (
    echo [ERROR] No se pudo extraer PHP
    pause
    exit /b 1
)

echo Configurando php.ini...
copy "%INSTALL_DIR%\php.ini-development" "%INSTALL_DIR%\php.ini" >nul

REM Habilitar extensiones basicas
powershell -Command "(Get-Content '%INSTALL_DIR%\php.ini') -replace ';extension=curl', 'extension=curl' -replace ';extension=mbstring', 'extension=mbstring' -replace ';extension=openssl', 'extension=openssl' -replace ';extension=pdo_mysql', 'extension=pdo_mysql' -replace ';extension=sockets', 'extension=sockets' -replace ';extension_dir = \"ext\"', 'extension_dir = \"ext\"' | Set-Content '%INSTALL_DIR%\php.ini'"

set "PHP_PATH=%INSTALL_DIR%\php.exe"
echo [OK] PHP instalado en %INSTALL_DIR%
echo.

:check_extensions
echo =====================================================================
echo  PASO 2: Verificando extensiones SQL Server
echo =====================================================================
echo.

"%PHP_PATH%" -m 2>nul | findstr /i "pdo_sqlsrv" >nul
if %ERRORLEVEL% equ 0 (
    echo [OK] Extensiones SQL Server ya instaladas
    goto :install_composer
)

echo Buscando extensiones SQL Server...
echo.

REM Intentar copiar desde XAMPP si esta disponible
if exist "C:\xampp\php\ext\php_sqlsrv_82_ts_x64.dll" (
    echo [INFO] Encontradas en XAMPP - copiando...
    copy "C:\xampp\php\ext\php_sqlsrv_82_ts_x64.dll"    "%INSTALL_DIR%\ext\" >nul
    copy "C:\xampp\php\ext\php_pdo_sqlsrv_82_ts_x64.dll" "%INSTALL_DIR%\ext\" >nul
    echo extension=php_sqlsrv_82_ts_x64.dll    >> "%INSTALL_DIR%\php.ini"
    echo extension=php_pdo_sqlsrv_82_ts_x64.dll >> "%INSTALL_DIR%\php.ini"
    echo [OK] Extensiones SQL Server instaladas desde XAMPP
    goto :install_ssl
)

REM Intentar copiar desde el directorio php_drivers/ junto al proyecto
if exist "%SCRIPT_DIR%php_drivers\php_sqlsrv_82_ts_x64.dll" (
    echo [INFO] Encontradas en php_drivers/ - copiando...
    copy "%SCRIPT_DIR%php_drivers\php_sqlsrv_82_ts_x64.dll"    "%INSTALL_DIR%\ext\" >nul
    copy "%SCRIPT_DIR%php_drivers\php_pdo_sqlsrv_82_ts_x64.dll" "%INSTALL_DIR%\ext\" >nul
    echo extension=php_sqlsrv_82_ts_x64.dll    >> "%INSTALL_DIR%\php.ini"
    echo extension=php_pdo_sqlsrv_82_ts_x64.dll >> "%INSTALL_DIR%\php.ini"
    echo [OK] Extensiones SQL Server instaladas desde php_drivers/
    goto :install_ssl
)

echo [AVISO] Extensiones SQL Server no encontradas automaticamente.
echo.
echo Para instalarlas:
echo   - Opcion A: Copia desde el PC principal:
echo       C:\xampp\php\ext\php_sqlsrv_82_ts_x64.dll
echo       C:\xampp\php\ext\php_pdo_sqlsrv_82_ts_x64.dll
echo     a: %INSTALL_DIR%\ext\
echo     y anade al final de %INSTALL_DIR%\php.ini:
echo       extension=php_sqlsrv_82_ts_x64.dll
echo       extension=php_pdo_sqlsrv_82_ts_x64.dll
echo.
echo   - Opcion B: Descarga desde Microsoft:
echo       https://learn.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server
echo.
echo [AVISO] Continuando sin extensiones - el sync NO funcionara hasta instalarlas.
echo.

:install_ssl
echo =====================================================================
echo  PASO 2b: Configurando certificados SSL
echo =====================================================================
echo.

REM Comprobar si ya esta configurado
findstr /i "curl.cainfo" "%INSTALL_DIR%\php.ini" >nul 2>&1
if %ERRORLEVEL% equ 0 (
    echo [OK] curl.cainfo ya configurado en php.ini
    goto :install_composer
)

REM Copiar cacert desde php_drivers/ si existe
if exist "%SCRIPT_DIR%php_drivers\cacert.pem" (
    copy "%SCRIPT_DIR%php_drivers\cacert.pem" "%INSTALL_DIR%\cacert.pem" >nul
    echo curl.cainfo="%INSTALL_DIR%\cacert.pem"     >> "%INSTALL_DIR%\php.ini"
    echo openssl.cafile="%INSTALL_DIR%\cacert.pem"  >> "%INSTALL_DIR%\php.ini"
    echo [OK] Certificados SSL configurados desde php_drivers/
    goto :install_composer
)

REM Copiar cacert desde XAMPP si existe
if exist "C:\xampp\apache\bin\curl-ca-bundle.crt" (
    copy "C:\xampp\apache\bin\curl-ca-bundle.crt" "%INSTALL_DIR%\cacert.pem" >nul
    echo curl.cainfo="%INSTALL_DIR%\cacert.pem"     >> "%INSTALL_DIR%\php.ini"
    echo openssl.cafile="%INSTALL_DIR%\cacert.pem"  >> "%INSTALL_DIR%\php.ini"
    echo [OK] Certificados SSL configurados desde XAMPP
    goto :install_composer
)

echo [INFO] cacert.pem no encontrado localmente - descargando desde curl.se...
powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; try { Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile '%INSTALL_DIR%\cacert.pem' -UseBasicParsing } catch { Write-Host 'Error descargando cacert' }"

if exist "%INSTALL_DIR%\cacert.pem" (
    echo curl.cainfo="%INSTALL_DIR%\cacert.pem"     >> "%INSTALL_DIR%\php.ini"
    echo openssl.cafile="%INSTALL_DIR%\cacert.pem"  >> "%INSTALL_DIR%\php.ini"
    echo [OK] Certificados SSL descargados e instalados automaticamente
    goto :install_composer
)

echo [AVISO] No se pudo obtener cacert.pem automaticamente.
echo         Los envios HTTPS al Manager fallaran con error SSL.
echo         Copia php_drivers\cacert.pem al directorio %INSTALL_DIR%\ y anade:
echo           curl.cainfo="%INSTALL_DIR%\cacert.pem"
echo         al final de %INSTALL_DIR%\php.ini
echo.

:install_composer
echo =====================================================================
echo  PASO 3: Instalando Composer
echo =====================================================================
echo.

if exist "%INSTALL_DIR%\composer.phar" (
    echo [OK] Composer ya esta instalado localmente
    goto :install_dependencies
)

where composer >nul 2>&1
if %ERRORLEVEL% equ 0 (
    echo [OK] Composer ya esta instalado en el sistema
    goto :install_dependencies
)

echo Descargando Composer...
powershell -Command "& {[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%COMPOSER_URL%' -OutFile '%INSTALL_DIR%\composer.phar' -UseBasicParsing}"

if exist "%INSTALL_DIR%\composer.phar" (
    echo [OK] Composer instalado en %INSTALL_DIR%\composer.phar
) else (
    echo [ERROR] No se pudo descargar Composer
    echo Descarga manualmente desde: https://getcomposer.org/download/
    pause
    exit /b 1
)

echo.

:install_dependencies
echo =====================================================================
echo  PASO 4: Instalando dependencias del proyecto
echo =====================================================================
echo.

cd /d "%SCRIPT_DIR%"

if exist "%INSTALL_DIR%\composer.phar" (
    echo Ejecutando: %PHP_PATH% composer.phar install
    "%PHP_PATH%" "%INSTALL_DIR%\composer.phar" install --no-interaction
) else (
    echo Ejecutando: composer install
    composer install --no-interaction
)

if %ERRORLEVEL% neq 0 (
    echo.
    echo [ERROR] Fallo la instalacion de dependencias
    echo Ejecuta manualmente: php composer.phar install
    pause
    exit /b 1
)

echo.
echo =====================================================================
echo  INSTALACION COMPLETADA
echo =====================================================================
echo.
echo PHP:      %PHP_PATH%
echo Proyecto: %SCRIPT_DIR%
echo.
echo =====================================================================
echo  SIGUIENTE PASO
echo =====================================================================
echo.
echo 1. Verifica que .env esta configurado correctamente
echo 2. Ejecuta como Administrador: install-task-admin.bat
echo 3. Reinicia el servidor o ejecuta: start-listener.bat
echo.
echo Para probar ahora, ejecuta: start-listener.bat
echo.
pause
