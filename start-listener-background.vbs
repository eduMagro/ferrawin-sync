' =====================================================================
'  Sync Listener - Inicia en segundo plano (sin ventana visible)
' =====================================================================
'
'  Este script inicia sync-listener.php en segundo plano.
'  Util para ejecutar al inicio de Windows.
'
'  Para detener: usar el Administrador de Tareas o:
'    taskkill /F /IM php.exe
'
' =====================================================================

Set WshShell = CreateObject("WScript.Shell")
Set FSO = CreateObject("Scripting.FileSystemObject")

' Obtener directorio del script (donde esta este archivo .vbs)
scriptDir = FSO.GetParentFolderName(WScript.ScriptFullName)

' Buscar PHP en ubicaciones comunes
phpPath = ""
If FSO.FileExists("C:\xampp\php\php.exe") Then
    phpPath = "C:\xampp\php\php.exe"
ElseIf FSO.FileExists("C:\php\php.exe") Then
    phpPath = "C:\php\php.exe"
ElseIf FSO.FileExists(scriptDir & "\php\php.exe") Then
    phpPath = scriptDir & "\php\php.exe"
Else
    ' Intentar encontrar en PATH
    Set objExec = WshShell.Exec("where php")
    phpPath = Trim(objExec.StdOut.ReadLine())
End If

If phpPath = "" Or Not FSO.FileExists(phpPath) Then
    MsgBox "No se encontro PHP. Instala XAMPP o PHP.", vbCritical, "Error"
    WScript.Quit 1
End If

' Cambiar al directorio del proyecto
WshShell.CurrentDirectory = scriptDir

' Ejecutar PHP en segundo plano (0 = oculto, False = no esperar)
WshShell.Run """" & phpPath & """ """ & scriptDir & "\sync-listener.php""", 0, False
