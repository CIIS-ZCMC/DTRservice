<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\TimeRecordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Device management routes
Route::get('/devices', [DeviceController::class, 'index']);
Route::get('/devices/{id}/status', [DeviceController::class, 'status']);
Route::get('/devices/{id}', [DeviceController::class, 'show']);
Route::get('/devices/{id}/power-off', [DeviceController::class, 'powerOff']);
Route::get('/devices/{id}/sync-time', [DeviceController::class, 'syncTime']);
Route::get('/devices/{id}/restart', [DeviceController::class, 'restart']);

// Time record routes
Route::get('/time-records', [TimeRecordController::class, 'index']);
Route::get('/time-records/{biometricId}/{date}', [TimeRecordController::class, 'show']);


Route::get('/compute-dtr/{biometricId}/{date}', [TimeRecordController::class, 'computeDTR']);