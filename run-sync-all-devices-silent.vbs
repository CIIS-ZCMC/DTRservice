' ============================================================================
' ZCMC DTRService - Silent Biometrics Device Sync Runner (Zero Window Popup)
' ============================================================================
' Purpose: Executes run-sync-all-devices.bat in the background with window hidden (0).
' Works with Windows Task Scheduler or can be double-clicked without any black CMD flash.
' ============================================================================

Set objFSO = CreateObject("Scripting.FileSystemObject")
strDir = objFSO.GetParentFolderName(WScript.ScriptFullName)
strBat = strDir & "\run-sync-all-devices.bat"

Set WshShell = CreateObject("WScript.Shell")
' Parameter 0 = vbHide (completely hides the command window)
' Parameter False = returns immediately without blocking
WshShell.Run Chr(34) & strBat & Chr(34), 0, False
Set WshShell = Nothing
