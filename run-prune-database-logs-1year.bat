@echo off
setlocal EnableDelayedExpansion

:: ============================================================================
:: ZCMC DTRService - Database Table Device Logs Pruning (1 Year Retention)
:: ============================================================================
:: Purpose: Prunes historical raw records in MySQL `device_logs` older than 1 year.
:: Safety:  - Archives records to compressed .json.gz in storage/app/archive/
::          - Strictly blocked if date is newer than 1 year before today.
::          - Computed DTR records in `dtr` table are permanently preserved.
:: Output:  Logged to storage\logs\device_logs_prune.log
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
echo  ZCMC DTRService - Database Table Device Logs Pruning (1 Year Retention)
echo  Started: %date% %time%
echo ============================================================================

echo [%date% %time%] [TASK] Starting database device_logs pruning (1-year retention)... >> "%~dp0storage\logs\device_logs_prune.log"

:: Run artisan command: older than 1 year, with archive, bypassing confirmation prompt
"%PHP_BIN%" artisan device-logs:prune --years=1 --archive --force %* >> "%~dp0storage\logs\device_logs_prune.log" 2>&1
set "CMD_STATUS=%ERRORLEVEL%"

if %CMD_STATUS% equ 0 (
    echo Pruning completed successfully.
    echo Check log file: storage\logs\device_logs_prune.log for details.
    echo [%date% %time%] [SUCCESS] Database device_logs pruning completed successfully. >> "%~dp0storage\logs\device_logs_prune.log"
) else (
    echo Pruning finished with exit code %CMD_STATUS%.
    echo Check log file: storage\logs\device_logs_prune.log for error details.
    echo [%date% %time%] [ERROR] Database device_logs pruning exited with error code %CMD_STATUS%. >> "%~dp0storage\logs\device_logs_prune.log"
)

exit /b %CMD_STATUS%
