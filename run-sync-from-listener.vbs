Set WshShell = CreateObject("WScript.Shell")
WshShell.Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\ferrawin-sync\sync-optimizado.php"" --nuevas --target=production", 0, False
