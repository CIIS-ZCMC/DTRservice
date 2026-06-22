<?php

use App\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Device push data endpoint (ZKTeco iclock) - catch all paths for debugging
Route::any('/iclock/{any}', [DeviceController::class, 'handleDevicePush'])->where('any', '.*');
