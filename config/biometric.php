<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Biometric Device
    |--------------------------------------------------------------------------
    |
    | The default device ID to use when no device ID is provided
    | to the sync command.
    |
    */
    'default_device' => env('BIOMETRIC_DEFAULT_DEVICE', 'DEVICE_001'),

    /*
    |--------------------------------------------------------------------------
    | Sync Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for biometric device synchronization.
    |
    */
    'sync' => [
        'timeout' => env('BIOMETRIC_SYNC_TIMEOUT', 30),
        'retry_attempts' => env('BIOMETRIC_SYNC_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('BIOMETRIC_SYNC_RETRY_DELAY', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Available Devices
    |--------------------------------------------------------------------------
    |
    | List of configured biometric devices.
    |
    */
    'devices' => [
        'DEVICE_001' => [
            'name' => 'Main Entrance',
            'ip' => env('BIOMETRIC_DEVICE_001_IP', '192.168.1.100'),
            'port' => env('BIOMETRIC_DEVICE_001_PORT', 80),
        ],
    ],
];
