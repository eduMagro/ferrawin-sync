' =====================================================================
'  Sync Listener - Inicia en segundo plano (sin ventana visible)
' =====================================================================
'
'  Este script inicia sync-listener.php en segundo plano.
'  Util para ejecutar al inicio de Windows.
'
'  Para detener: usar el Administrador de Tareas o:
'    taskkill /F /IM php.exe /FI "WINDOWTITLE eq *sync-listener*"
'
' =====================================================================

Set WshShell = CreateObject("WScript.Shell")

' Cambiar al directorio del proyecto
WshShell.CurrentDirectory = "C:\xampp\htdocs\ferrawin-sync"

' Ejecutar PHP en segundo plano (0 = oculto, False = no esperar)
WshShell.Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\ferrawin-sync\sync-listener.php""", 0, False
