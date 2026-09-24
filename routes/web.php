<?php

use App\Http\Controllers\BiometricHrblizMappingController;
use App\Http\Controllers\CommandRunnerController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceLogAlertController;
use App\Http\Controllers\LogViewerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Device management web view
Route::get('/devices', [DeviceController::class, 'managementView'])->name('devices.index');

// HRBLIZ Biometrics Mapping & Verification (Temporary Tool)
Route::get('/biometrics/hrbliz', [BiometricHrblizMappingController::class, 'index'])->name('biometrics.hrbliz');
Route::get('/biometrics/hrbliz/list', [BiometricHrblizMappingController::class, 'list'])->name('biometrics.hrbliz.list');
Route::post('/biometrics/hrbliz/update', [BiometricHrblizMappingController::class, 'updateSingle'])->name('biometrics.hrbliz.update');
Route::post('/biometrics/hrbliz/analyze', [BiometricHrblizMappingController::class, 'analyze'])->name('biometrics.hrbliz.analyze');
Route::post('/biometrics/hrbliz/batch-merge', [BiometricHrblizMappingController::class, 'batchMerge'])->name('biometrics.hrbliz.batch-merge');
Route::get('/biometrics/hrbliz/candidates', [BiometricHrblizMappingController::class, 'searchCandidates'])->name('biometrics.hrbliz.candidates');
Route::get('/biometrics/hrbliz/sample', [BiometricHrblizMappingController::class, 'getSampleData'])->name('biometrics.hrbliz.sample');

// Log viewer routes
Route::get('/logs', [LogViewerController::class, 'index'])->name('logs.index');
Route::get('/logs/view', [LogViewerController::class, 'show'])->name('logs.show');
Route::post('/logs/clear', [LogViewerController::class, 'clear'])->name('logs.clear');

// Device log alert routes
Route::get('/logs/alert', [DeviceLogAlertController::class, 'index'])->name('logs.alert');
Route::get('/logs/alert/scan', [DeviceLogAlertController::class, 'scan'])->name('logs.alert.scan');
Route::get('/logs/alert/scan-db', [DeviceLogAlertController::class, 'scanDatabase'])->name('logs.alert.scan-db');
Route::get('/logs/alert/date/{date}', [DeviceLogAlertController::class, 'dateEntries'])->name('logs.alert.date');
Route::get('/logs/alert/file/{filename}', [DeviceLogAlertController::class, 'fileContents'])->name('logs.alert.file');
Route::match(['get', 'post'], '/logs/alert/print', [DeviceLogAlertController::class, 'printDtrLogs'])->name('logs.alert.print');
Route::match(['get', 'post'], '/logs/alert/preview-print', [DeviceLogAlertController::class, 'previewPrintLogs'])->name('logs.alert.preview-print');
Route::get('/logs/alert/employees', [DeviceLogAlertController::class, 'searchEmployees'])->name('logs.alert.employees');
Route::get('/logs/alert/attendance-logs', [DeviceLogAlertController::class, 'fetchAttendanceLogs'])->name('logs.alert.attendance-logs');
Route::post('/logs/alert/generate-device-logs', [DeviceLogAlertController::class, 'generateDeviceLogs'])->name('logs.alert.generate-device-logs');

// Command runner routes
Route::get('/command-runner/manifest', [CommandRunnerController::class, 'getManifest'])->name('command-runner.manifest');
Route::get('/command-runner/employees', [CommandRunnerController::class, 'searchEmployees'])->name('command-runner.employees');
Route::post('/command-runner/run', [CommandRunnerController::class, 'runCommand'])->name('command-runner.run');

// Device push data endpoint (ZKTeco iclock) - catch all paths for debugging
Route::any('/iclock/{any}', [DeviceController::class, 'handleDevicePush'])->where('any', '.*');
