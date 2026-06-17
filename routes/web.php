<?php

use App\Http\Controllers\BiometricController;
use App\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Biometric device routes
Route::prefix('api')->group(function () {
    Route::post('/biometric/sync', [BiometricController::class, 'sync']);
    Route::get('/biometric/time-logs', [BiometricController::class, 'index']);
});

// Device management routes
Route::prefix('api')->group(function () {
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::get('/devices/{id}/status', [DeviceController::class, 'status']);
    Route::get('/devices/{id}', [DeviceController::class, 'show']);
    Route::post('/devices/{id}/power-on', [DeviceController::class, 'powerOn']);
    Route::post('/devices/{id}/power-off', [DeviceController::class, 'powerOff']);
    Route::post('/devices/{id}/sync-time', [DeviceController::class, 'syncTime']);
    Route::post('/devices/{id}/restart', [DeviceController::class, 'restart']);
});
