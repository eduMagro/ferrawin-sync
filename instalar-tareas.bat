@echo off
REM Lanzador para install-tasks.ps1
REM Doble clic para instalar ambas tareas programadas

powershell -ExecutionPolicy Bypass -Command "Start-Process powershell -ArgumentList '-ExecutionPolicy Bypass -File ""%~dp0install-tasks.ps1""' -Verb RunAs"
