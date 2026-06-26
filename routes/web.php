<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\LogViewerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Log viewer routes
Route::get('/logs', [LogViewerController::class, 'index'])->name('logs.index');
Route::get('/logs/view', [LogViewerController::class, 'show'])->name('logs.show');
Route::post('/logs/clear', [LogViewerController::class, 'clear'])->name('logs.clear');

// Device push data endpoint (ZKTeco iclock) - catch all paths for debugging
Route::any('/iclock/{any}', [DeviceController::class, 'handleDevicePush'])->where('any', '.*');
