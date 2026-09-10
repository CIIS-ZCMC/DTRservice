<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DtrReportController;
use App\Http\Controllers\TimeRecordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Device management routes
Route::get('/devices', [DeviceController::class, 'index']);
Route::get('/devices/paginated', [DeviceController::class, 'getPaginatedDevices']);
Route::get('/devices/all', [DeviceController::class, 'getAllWithStatus']);
Route::match(['get', 'post'], '/devices/test-all', [DeviceController::class, 'testAllConnections']);
Route::match(['get', 'post'], '/devices/sync-time-all', [DeviceController::class, 'syncAllTime']);
Route::post('/devices/restart-batch', [DeviceController::class, 'restartBatch']);
Route::match(['get', 'post'], '/devices/pull-logs', [DeviceController::class, 'pullLogs']);
Route::match(['get', 'post'], '/devices/{id}/pull-logs', [DeviceController::class, 'pullDeviceLogs']);
Route::match(['get', 'post'], '/devices/request-resend', [DeviceController::class, 'requestLogResend']);
Route::match(['get', 'post'], '/devices/{id}/request-resend', [DeviceController::class, 'requestDeviceLogResend']);
Route::match(['get', 'post'], '/devices/clear-logs', [DeviceController::class, 'clearAttendanceLogs']);
Route::match(['get', 'post'], '/devices/{id}/clear-logs', [DeviceController::class, 'clearDeviceAttendanceLogs']);
Route::match(['get', 'post'], '/device-logs/prune', [DeviceController::class, 'pruneDatabaseLogs']);
Route::get('/devices/{id}/status', [DeviceController::class, 'status']);
Route::match(['get', 'post'], '/devices/{id}/test-connection', [DeviceController::class, 'testConnection']);
Route::match(['put', 'post', 'patch'], '/devices/{id}/name', [DeviceController::class, 'updateName']);
Route::match(['put', 'post', 'patch'], '/devices/{id}/roles', [DeviceController::class, 'updateRoles']);
Route::post('/dtr-device-updatedevicestatus', [DeviceController::class, 'updateDeviceStatusLegacy']);
Route::get('/devices/{id}', [DeviceController::class, 'show']);
Route::get('/devices/{id}/power-off', [DeviceController::class, 'powerOff']);
Route::match(['get', 'post'], '/devices/{id}/sync-time', [DeviceController::class, 'syncTime']);
Route::match(['get', 'post'], '/devices/{id}/restart', [DeviceController::class, 'restart']);

// Time record routes
Route::get('/time-records', [TimeRecordController::class, 'index']);
Route::get('/time-records/{biometricId}/{date}', [TimeRecordController::class, 'show']);

Route::get('/compute-dtr/{biometricId}/{date}', [TimeRecordController::class, 'computeDTR']);

// DTR Report routes
Route::middleware('dtr.token')->group(function () {
    Route::get('/dtr/report/{biometricId}/{year}/{month}', [DtrReportController::class, 'generate']);
    Route::get('/dtr/download/{biometricId}/{year}/{month}', [DtrReportController::class, 'download']);
    Route::get('/dtr/json/{biometricId}/{year}/{month}', [DtrReportController::class, 'json']);
});

// DTR Self-service
Route::get('/dtr-self', [DtrReportController::class, 'dtrSelf']);
