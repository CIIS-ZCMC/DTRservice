@echo off
setlocal EnableDelayedExpansion

:: ============================================================================
:: ZCMC DTRService - Biometric Device Fast Sync Runner (Silent)
:: ============================================================================
:: Purpose: Fast masterlist sync across all active biometric devices.
::          Pushes user profiles (Grp=1, TZ=1) and enrolled fingerprints
::          without deleting unassigned slots (runs up to 80% faster).
:: Mode:    Completely silent execution (all output redirected to log file).
:: Output:  Logged to storage\logs\biometrics_sync_device.log
:: ============================================================================

cd /d "%~dp0"

:: 1. Resolve PHP binary
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

:: 2. Ensure log directory exists
if not exist "%~dp0storage\logs" (
    mkdir "%~dp0storage\logs" 2>nul
)

:: 3. Log task start
echo ============================================================================ >> "%~dp0storage\logs\biometrics_sync_device.log"
echo  ZCMC DTRService - Biometrics Device Fast Sync (--no-clean) >> "%~dp0storage\logs\biometrics_sync_device.log"
echo  Started: %date% %time% >> "%~dp0storage\logs\biometrics_sync_device.log"
echo ============================================================================ >> "%~dp0storage\logs\biometrics_sync_device.log"
echo [%date% %time%] [TASK] Starting fast biometric device sync (--no-clean)... >> "%~dp0storage\logs\biometrics_sync_device.log"

:: 4. Run artisan command in fast mode silently
"%PHP_BIN%" artisan biometrics:sync-device --all-devices --no-clean --no-interaction --no-ansi %* >> "%~dp0storage\logs\biometrics_sync_device.log" 2>&1
set "CMD_STATUS=%ERRORLEVEL%"

if %CMD_STATUS% equ 0 (
    echo [%date% %time%] [SUCCESS] Fast biometric device sync completed successfully. >> "%~dp0storage\logs\biometrics_sync_device.log"
) else (
    echo [%date% %time%] [ERROR] Fast biometric device sync exited with error code %CMD_STATUS%. >> "%~dp0storage\logs\biometrics_sync_device.log"
)

exit /b %CMD_STATUS%
