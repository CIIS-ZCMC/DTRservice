@echo off
setlocal EnableDelayedExpansion

:: ============================================================================
:: ZCMC DTRService - Biometric Device Attendance Logs Clearance (1 Week)
:: ============================================================================
:: Purpose: Safely clears attendance logs on physical biometric devices that
::          have not been cleared in 1 week (7 days).
:: Safety:  - Pre-syncs and verifies all punches to MySQL database before wipe.
::          - Skips offline devices safely (no accidental wiped punches).
::          - Preserves all employee registrations, fingerprints, and settings.
:: Output:  Logged to storage\logs\device_clear.log
:: ============================================================================

cd /d "%~dp0"

:: Resolve PHP binary
set "PHP_BIN=php"
if defined PHP_PATH (
    set "PHP_BIN=%PHP_PATH%"
) else (
    where php >nul 2>&1
    if !ERRORLEVEL! neq 0 (
        if exist "C:\wamp64\bin\php\php8.4.15\php.exe" (
            set "PHP_BIN=C:\wamp64\bin\php\php8.4.15\php.exe"
        )
    )
)

:: Ensure log directory exists
if not exist "%~dp0storage\logs" (
    mkdir "%~dp0storage\logs" 2>nul
)

echo ============================================================================
echo  ZCMC DTRService - Clearing Device Attendance Logs (Older than 1 Week)
echo  Started: %date% %time%
echo ============================================================================

echo [%date% %time%] [TASK] Starting 1-week biometric device log clearance... >> "%~dp0storage\logs\device_clear.log"

:: Run artisan command and append output to log file
"%PHP_BIN%" artisan devices:clear-logs --all --catch-up --older-than=7 --force --method=both %* >> "%~dp0storage\logs\device_clear.log" 2>&1
set "CMD_STATUS=%ERRORLEVEL%"

if %CMD_STATUS% equ 0 (
    echo Clearance command completed successfully.
    echo Check log file: storage\logs\device_clear.log for details.
    echo [%date% %time%] [SUCCESS] 1-week biometric device log clearance completed successfully. >> "%~dp0storage\logs\device_clear.log"
) else (
    echo Clearance command finished with exit code %CMD_STATUS%.
    echo Check log file: storage\logs\device_clear.log for error details.
    echo [%date% %time%] [ERROR] 1-week biometric device log clearance exited with error code %CMD_STATUS%. >> "%~dp0storage\logs\device_clear.log"
)

exit /b %CMD_STATUS%
