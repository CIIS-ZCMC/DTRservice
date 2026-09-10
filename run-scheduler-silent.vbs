' ============================================================================
' ZCMC DTRService - Silent Task Scheduler Runner (Zero Window Popup)
' ============================================================================
' Purpose: Executes run-scheduler.bat in the background with window hidden (0).
' Works with Windows Task Scheduler or can be double-clicked without any black CMD flash.
' ============================================================================

Set objFSO = CreateObject("Scripting.FileSystemObject")
strDir = objFSO.GetParentFolderName(WScript.ScriptFullName)
strBat = strDir & "\run-scheduler.bat"

Set WshShell = CreateObject("WScript.Shell")
' The parameter "0" hides the command window completely (vbHide)
' The parameter "False" returns immediately without blocking
WshShell.Run Chr(34) & strBat & Chr(34), 0, False
Set WshShell = Nothing
