' Runs the Laravel scheduler (php artisan schedule:work) without a console window.
' Started at logon by the "AplikasiOdp Penjadwal" task that pasang-penjadwal.ps1 registers.
' Stays running so Task Scheduler can restart it if PHP stops.
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")
shell.CurrentDirectory = fso.GetParentFolderName(fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName)))
php = shell.ExpandEnvironmentStrings("%ODP_PHP%")
If php = "%ODP_PHP%" Then php = "C:\php\php.exe"
WScript.Quit shell.Run("""" & php & """ artisan schedule:work", 0, True)
