@echo off
setlocal EnableDelayedExpansion

:: ============================================================================
:: ZCMC DTRService - Laravel Task Scheduler Runner
:: ============================================================================
:: Purpose: Runs Laravel's schedule:run command.
:: Intended for Windows Task Scheduler to execute repeatedly (e.g., every 1 or 5 minutes).
:: It executes any due tasks defined in routes/console.php:
::   1. dtr:process --all (Every 5 minutes)
::   2. devices:clear-logs (1-week device clearance: Sunday 23:55 + daily 12:00 catch-up)
::   3. device-logs:prune --years=1 --archive (1 year DB pruning on 1st of month)
:: ============================================================================

:: 1. Navigate to project root directory
cd /d "%~dp0"

:: 2. Resolve PHP executable
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

:: 3. Ensure storage/logs directory exists
if not exist "%~dp0storage\logs" (
    mkdir "%~dp0storage\logs" 2>nul
)

:: 4. Run Laravel Scheduler
"%PHP_BIN%" artisan schedule:run 1>> "%~dp0storage\logs\scheduler.log" 2>&1

exit /b %ERRORLEVEL%
