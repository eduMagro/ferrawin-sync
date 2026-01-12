@echo off
echo ============================================
echo SINCRONIZACION COMPLETA FERRAWIN - LOCAL
echo Inicio: %date% %time%
echo ============================================

cd /d C:\xampp\htdocs\ferrawin-sync

echo.
echo [1/9] Importando 2018 (279 planillas)...
php sync.php --año 2018
if %ERRORLEVEL% NEQ 0 echo ERROR en 2018

echo.
echo [2/9] Importando 2019 (1618 planillas)...
php sync.php --año 2019
if %ERRORLEVEL% NEQ 0 echo ERROR en 2019

echo.
echo [3/9] Importando 2020 (2163 planillas)...
php sync.php --año 2020
if %ERRORLEVEL% NEQ 0 echo ERROR en 2020

echo.
echo [4/9] Importando 2021 (3068 planillas)...
php sync.php --año 2021
if %ERRORLEVEL% NEQ 0 echo ERROR en 2021

echo.
echo [5/9] Importando 2022 (4584 planillas)...
php sync.php --año 2022
if %ERRORLEVEL% NEQ 0 echo ERROR en 2022

echo.
echo [6/9] Importando 2023 (5223 planillas)...
php sync.php --año 2023
if %ERRORLEVEL% NEQ 0 echo ERROR en 2023

echo.
echo [7/9] Importando 2024 (8145 planillas)...
php sync.php --año 2024
if %ERRORLEVEL% NEQ 0 echo ERROR en 2024

echo.
echo [8/9] Importando 2025 (7998 planillas)...
php sync.php --año 2025
if %ERRORLEVEL% NEQ 0 echo ERROR en 2025

echo.
echo [9/9] Importando 2026 (22 planillas)...
php sync.php --año 2026
if %ERRORLEVEL% NEQ 0 echo ERROR en 2026

echo.
echo ============================================
echo SINCRONIZACION COMPLETADA
echo Fin: %date% %time%
echo ============================================
pause
