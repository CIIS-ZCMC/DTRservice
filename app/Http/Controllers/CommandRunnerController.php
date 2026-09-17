<?php

namespace App\Http\Controllers;

use App\Models\Biometrics;
use App\Models\Devices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandRunnerController extends Controller
{
    /**
     * Allowed commands whitelist with execution configuration
     */
    protected const ALLOWED_COMMANDS = [
        'biometrics:sync-device' => [
            'name' => 'biometrics:sync-device',
            'title' => 'Sync Biometrics to Device',
            'category' => 'Biometrics & Provisioning',
            'guide' => 'NEW_DEVICE_GUIDE.md',
            'description' => 'Provision employee profile(s) and enrolled fingerprint templates from database to target terminal(s).',
            'pin_requirement' => 'optional',
            'device_requirement' => 'device_or_all',
            'danger_level' => 'normal',
        ],
        'biometrics:check-device' => [
            'name' => 'biometrics:check-device',
            'title' => 'Check Live Enrolled Fingers & Auto-Fix',
            'category' => 'Diagnostics & Verification',
            'guide' => 'NEW_DEVICE_GUIDE.md',
            'description' => 'Inspect physical terminal memory via TAD/SOAP in real time for slots 0-9 and optionally auto-fix or purge ghosts.',
            'pin_requirement' => 'required',
            'device_requirement' => 'device_or_all',
            'danger_level' => 'notice',
        ],
        'biometrics:check-device-match' => [
            'name' => 'biometrics:check-device-match',
            'title' => 'Check Device Template Matches',
            'category' => 'Diagnostics & Verification',
            'guide' => 'NEW_DEVICE_GUIDE.md',
            'description' => 'Inspect physical terminal memory to detect identical templates matching other employee PINs.',
            'pin_requirement' => 'required',
            'device_requirement' => 'device_or_all',
            'danger_level' => 'notice',
        ],
        'biometrics:find-duplicates' => [
            'name' => 'biometrics:find-duplicates',
            'title' => 'Find Duplicate Biometrics in DB',
            'category' => 'Diagnostics & Verification',
            'guide' => 'NEW_DEVICE_GUIDE.md',
            'description' => 'Audit central database for identical/duplicate fingerprint templates against a PIN or across all employees.',
            'pin_requirement' => 'optional',
            'device_requirement' => 'none',
            'danger_level' => 'safe',
        ],
        'biometrics:command-status' => [
            'name' => 'biometrics:command-status',
            'title' => 'Command Queue & Sync Status',
            'category' => 'Queue & Monitoring',
            'guide' => 'NEW_DEVICE_GUIDE.md',
            'description' => 'View real-time execution counts and sync status of queued biometric commands.',
            'pin_requirement' => 'optional',
            'device_requirement' => 'optional',
            'danger_level' => 'safe',
        ],
        'biometrics:clear-queue' => [
            'name' => 'biometrics:clear-queue',
            'title' => 'Clear Biometric Queue',
            'category' => 'Queue & Monitoring',
            'guide' => 'NEW_DEVICE_GUIDE.md',
            'description' => 'Instantly cancel and purge all pending commands across all queue files. Terminals stop receiving commands.',
            'pin_requirement' => 'none',
            'device_requirement' => 'none',
            'danger_level' => 'warning',
        ],
        'biometrics:delete-user' => [
            'name' => 'biometrics:delete-user',
            'title' => 'Delete User Profile from Devices',
            'category' => 'User & Template Deletion',
            'guide' => 'NEW_DEVICE_GUIDE.md',
            'description' => 'Purge user profile and all fingerprint templates from target device(s), and optionally central database.',
            'pin_requirement' => 'required',
            'device_requirement' => 'device_or_all',
            'danger_level' => 'danger',
        ],
        'biometrics:delete-finger' => [
            'name' => 'biometrics:delete-finger',
            'title' => 'Delete Specific Fingerprint (Slot 0-9)',
            'category' => 'User & Template Deletion',
            'guide' => 'NEW_DEVICE_GUIDE.md',
            'description' => 'Delete a specific enrolled finger slot from database and dispatch deletion commands to all active devices.',
            'pin_requirement' => 'required',
            'device_requirement' => 'none',
            'danger_level' => 'warning',
        ],
        'biometrics:import-from-logs' => [
            'name' => 'biometrics:import-from-logs',
            'title' => 'Import Templates from Raw Logs',
            'category' => 'Log Recovery & Pull',
            'guide' => 'NEW_DEVICE_GUIDE.md',
            'description' => 'Scan device logs to recover dropped fingerprint templates into the biometrics table and sync to devices.',
            'pin_requirement' => 'optional',
            'device_requirement' => 'none',
            'danger_level' => 'normal',
        ],
        'devices:pull-logs' => [
            'name' => 'devices:pull-logs',
            'title' => 'Pull or Resend Attendance Logs',
            'category' => 'Log Recovery & Pull',
            'guide' => 'MANUAL_DEVICE_LOG_PULLING_GUIDE.md',
            'description' => 'Pull attendance logs directly over SOAP (port 80) or queue ADMS HTTP push resend commands.',
            'pin_requirement' => 'optional',
            'device_requirement' => 'device_or_all',
            'danger_level' => 'normal',
        ],
        'devices:clear-logs' => [
            'name' => 'devices:clear-logs',
            'title' => 'Clear Terminal Attendance Logs',
            'category' => 'Maintenance & Pruning',
            'guide' => 'MANUAL_DEVICE_LOG_PULLING_GUIDE.md',
            'description' => 'Safely clear attendance buffer from physical biometric devices with zero-data-loss pre-sync verification.',
            'pin_requirement' => 'none',
            'device_requirement' => 'device_or_all',
            'danger_level' => 'danger',
        ],
        'device-logs:prune' => [
            'name' => 'device-logs:prune',
            'title' => 'Prune Database Attendance Logs',
            'category' => 'Maintenance & Pruning',
            'guide' => 'MANUAL_DEVICE_LOG_PULLING_GUIDE.md',
            'description' => 'Delete historical attendance records from MySQL database older than specified days (default 365 days).',
            'pin_requirement' => 'none',
            'device_requirement' => 'none',
            'danger_level' => 'danger',
        ],
    ];

    /**
     * Get manifest of supported commands and devices for GUI initialization
     */
    public function getManifest(): JsonResponse
    {
        $devices = Devices::select('id', 'device_name', 'serial_number', 'ip_address', 'is_active', 'is_registration', 'for_attendance')
            ->orderBy('is_active', 'desc')
            ->orderBy('device_name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'commands' => self::ALLOWED_COMMANDS,
            'devices' => $devices,
            'finger_names' => Biometrics::FINGER_NAMES,
        ]);
    }

    /**
     * Search enrolled employees by PIN or name for autocomplete
     */
    public function searchEmployees(Request $request): JsonResponse
    {
        $q = trim((string)$request->query('q', ''));

        $query = Biometrics::select('id', 'biometric_id', 'name');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('biometric_id', 'LIKE', "{$q}%")
                    ->orWhere('name', 'LIKE', "%{$q}%");
            });
        }

        $results = $query->orderBy('biometric_id', 'asc')
            ->limit(25)
            ->get();

        $employees = $results->map(function ($b) {
            return [
                'biometric_id' => (string)$b->biometric_id,
                'name' => $b->name ?: 'Employee #' . $b->biometric_id,
            ];
        });

        return response()->json([
            'success' => true,
            'employees' => $employees,
        ]);
    }

    /**
     * Execute a whitelisted Artisan command and capture output
     */
    public function runCommand(Request $request): JsonResponse
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '2048M');

        $commandKey = (string)$request->input('command');

        if (!isset(self::ALLOWED_COMMANDS[$commandKey])) {
            return response()->json([
                'success' => false,
                'message' => "Unauthorized or unknown command: [{$commandKey}]. Only registered guide commands are permitted.",
            ], 422);
        }

        $commandConfig = self::ALLOWED_COMMANDS[$commandKey];
        $params = $request->input('params', []);

        // Resolve device target
        $deviceTarget = $request->input('device_target', 'all'); // 'all' or device id
        $device = null;
        if ($deviceTarget !== 'all' && is_numeric($deviceTarget)) {
            $device = Devices::find((int)$deviceTarget);
            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => "Device with ID {$deviceTarget} not found in database.",
                ], 404);
            }
        }

        // Validate PIN requirement
        $pin = $request->input('pin');
        if (!empty($pin)) {
            $pin = (int)$pin;
        } else {
            $pin = null;
        }

        if ($commandConfig['pin_requirement'] === 'required' && empty($pin)) {
            return response()->json([
                'success' => false,
                'message' => "Command [{$commandKey}] requires a valid Employee Biometric PIN.",
            ], 422);
        }

        // Build Artisan arguments and options
        $artisanParams = [];

        try {
            switch ($commandKey) {
                case 'biometrics:sync-device':
                    if ($deviceTarget === 'all') {
                        $artisanParams['--all-devices'] = true;
                    } elseif ($device) {
                        $artisanParams['device_sn'] = $device->serial_number;
                    } else {
                        return response()->json(['success' => false, 'message' => 'Target device or --all-devices is required.'], 422);
                    }

                    if ($pin) {
                        $artisanParams['--pin'] = (string)$pin;
                    }

                    if (!empty($params['no_clean'])) {
                        $artisanParams['--no-clean'] = true;
                    }

                    if (!empty($params['table'])) {
                        $artisanParams['--table'] = true;
                    }
                    break;

                case 'biometrics:check-device':
                    $artisanParams['pin'] = (string)$pin;

                    if ($deviceTarget === 'all') {
                        $artisanParams['--all-devices'] = true;
                    } elseif ($device) {
                        $artisanParams['device_sn'] = $device->serial_number;
                    } else {
                        return response()->json(['success' => false, 'message' => 'Target device or --all-devices is required.'], 422);
                    }

                    $mode = $params['mode'] ?? 'inspect';
                    if ($mode === 'fix') {
                        $artisanParams['--fix'] = true;
                        $artisanParams['--force'] = true;
                    } elseif ($mode === 'clean') {
                        $artisanParams['--clean'] = true;
                        $artisanParams['--force'] = true;
                    }
                    break;

                case 'biometrics:check-device-match':
                    $artisanParams['pin'] = (string)$pin;

                    if ($deviceTarget === 'all') {
                        $artisanParams['--all-devices'] = true;
                    } elseif ($device) {
                        $artisanParams['device_sn'] = $device->serial_number;
                    } else {
                        return response()->json(['success' => false, 'message' => 'Target device or --all-devices is required.'], 422);
                    }

                    if (!empty($params['compare_pin'])) {
                        $artisanParams['--compare-pin'] = (int)$params['compare_pin'];
                    }

                    if (!empty($params['clean'])) {
                        $artisanParams['--clean'] = true;
                        $artisanParams['--force'] = true;
                    }

                    if (!empty($params['db_only'])) {
                        $artisanParams['--db-only'] = true;
                    }
                    break;

                case 'biometrics:find-duplicates':
                    if ($pin) {
                        $artisanParams['pin'] = (string)$pin;
                    } else {
                        $artisanParams['--all'] = true;
                    }
                    break;

                case 'biometrics:command-status':
                    if ($device) {
                        $artisanParams['--device'] = $device->serial_number;
                    }
                    if ($pin) {
                        $artisanParams['--pin'] = (string)$pin;
                    }
                    if (!empty($params['status'])) {
                        $artisanParams['--status'] = strtoupper($params['status']);
                    }
                    if (!empty($params['limit'])) {
                        $artisanParams['--limit'] = (int)$params['limit'];
                    } else {
                        $artisanParams['--limit'] = 50;
                    }
                    break;

                case 'biometrics:clear-queue':
                    // No parameters required
                    break;

                case 'biometrics:delete-user':
                    $artisanParams['pin'] = (string)$pin;

                    if ($deviceTarget === 'all') {
                        $artisanParams['--all-devices'] = true;
                    } elseif ($device) {
                        $artisanParams['device_sn'] = $device->serial_number;
                    } else {
                        return response()->json(['success' => false, 'message' => 'Target device or --all-devices is required.'], 422);
                    }

                    if (!empty($params['with_db'])) {
                        $artisanParams['--with-db'] = true;
                    }
                    break;

                case 'biometrics:delete-finger':
                    $artisanParams['pin'] = (string)$pin;
                    if (!isset($params['fid']) || $params['fid'] === '') {
                        return response()->json(['success' => false, 'message' => 'Finger ID slot (0-9) is required.'], 422);
                    }
                    $artisanParams['fid'] = (int)$params['fid'];
                    break;

                case 'biometrics:import-from-logs':
                    if ($pin) {
                        $artisanParams['--pin'] = (string)$pin;
                    }
                    if (!empty($params['sync_devices'])) {
                        $artisanParams['--sync-devices'] = true;
                    }
                    break;

                case 'devices:pull-logs':
                    if ($deviceTarget === 'all') {
                        $artisanParams['--all'] = true;
                    } elseif ($device) {
                        $artisanParams['device_id'] = $device->id;
                    } else {
                        return response()->json(['success' => false, 'message' => 'Target device or --all is required.'], 422);
                    }

                    if (!empty($params['resend'])) {
                        $artisanParams['--resend'] = true;
                        if (!empty($params['type'])) {
                            $artisanParams['--type'] = $params['type'];
                        }
                    }

                    if (!empty($params['date'])) {
                        $artisanParams['--date'] = $params['date'];
                    } elseif (!empty($params['start_date']) || !empty($params['end_date'])) {
                        if (!empty($params['start_date'])) {
                            $artisanParams['--start-date'] = $params['start_date'];
                        }
                        if (!empty($params['end_date'])) {
                            $artisanParams['--end-date'] = $params['end_date'];
                        }
                    }

                    if ($pin) {
                        $artisanParams['--pin'] = (string)$pin;
                    }
                    break;

                case 'devices:clear-logs':
                    if ($deviceTarget === 'all') {
                        $artisanParams['--all'] = true;
                    } elseif ($device) {
                        $artisanParams['device_id'] = $device->id;
                    } else {
                        return response()->json(['success' => false, 'message' => 'Target device or --all is required.'], 422);
                    }

                    $artisanParams['--force'] = true; // non-interactive bypass

                    if (!empty($params['method'])) {
                        $artisanParams['--method'] = $params['method'];
                    }

                    if (!empty($params['dry_run'])) {
                        $artisanParams['--dry-run'] = true;
                    }

                    if (!empty($params['catch_up'])) {
                        $artisanParams['--catch-up'] = true;
                        if (!empty($params['older_than'])) {
                            $artisanParams['--older-than'] = (int)$params['older_than'];
                        }
                    }

                    if (!empty($params['skip_sync'])) {
                        $artisanParams['--skip-sync'] = true;
                    }
                    break;

                case 'device-logs:prune':
                    $artisanParams['--force'] = true;

                    if (!empty($params['older_than'])) {
                        $artisanParams['--older-than'] = (int)$params['older_than'];
                    }

                    if (!empty($params['dry_run'])) {
                        $artisanParams['--dry-run'] = true;
                    }
                    break;
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter error: ' . $e->getMessage(),
            ], 422);
        }

        // Build CLI command representation string for display
        $cliString = $this->buildCliString($commandKey, $artisanParams);

        // Execute command and capture output
        $startTime = microtime(true);
        $outputBuffer = new BufferedOutput();

        try {
            Log::info("CommandRunner executing: php artisan {$cliString}");
            $exitCode = Artisan::call($commandKey, $artisanParams, $outputBuffer);
            $outputText = $outputBuffer->fetch();
            $duration = round(microtime(true) - $startTime, 2);

            return response()->json([
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'command' => "php artisan {$cliString}",
                'output' => $outputText ?: "(Command completed with no terminal output)",
                'duration' => "{$duration}s",
                'duration_ms' => (int)($duration * 1000),
                'executed_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);
            Log::error("CommandRunner error executing [{$commandKey}]: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'exit_code' => 1,
                'command' => "php artisan {$cliString}",
                'output' => "FATAL ERROR:\n" . $e->getMessage() . "\n\nStack trace:\n" . $e->getTraceAsString(),
                'duration' => "{$duration}s",
                'duration_ms' => (int)($duration * 1000),
                'executed_at' => now()->toDateTimeString(),
            ], 500);
        }
    }

    /**
     * Helper to render human-readable CLI string
     */
    protected function buildCliString(string $command, array $params): string
    {
        $parts = [$command];

        foreach ($params as $key => $val) {
            if (str_starts_with($key, '--')) {
                if ($val === true) {
                    $parts[] = $key;
                } elseif ($val !== false && $val !== null && $val !== '') {
                    $parts[] = "{$key}={$val}";
                }
            } else {
                $parts[] = (string)$val;
            }
        }

        return implode(' ', $parts);
    }
}
