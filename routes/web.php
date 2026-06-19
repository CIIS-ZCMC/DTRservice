<?php

use App\Http\Controllers\BiometricController;
use App\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


// Device management routes
Route::prefix('api')->group(function () {
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::get('/devices/{id}/status', [DeviceController::class, 'status']);
    Route::get('/devices/{id}', [DeviceController::class, 'show']);
    Route::get('/devices/{id}/power-off', [DeviceController::class, 'powerOff']);
    Route::get('/devices/{id}/sync-time', [DeviceController::class, 'syncTime']);
    Route::get('/devices/{id}/restart', [DeviceController::class, 'restart']);
});

// Device push data endpoint (ZKTeco iclock) - catch all paths for debugging
Route::any('/iclock/{any}', [DeviceController::class, 'handleDevicePush'])->where('any', '.*');
