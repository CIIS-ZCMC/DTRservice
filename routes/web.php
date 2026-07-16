<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceLogAlertController;
use App\Http\Controllers\LogViewerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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

// Device push data endpoint (ZKTeco iclock) - catch all paths for debugging
Route::any('/iclock/{any}', [DeviceController::class, 'handleDevicePush'])->where('any', '.*');
