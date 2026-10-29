<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ZKTeco Inbound Punch Ingestion API & ADMS Integration Server
    |--------------------------------------------------------------------------
    |
    | Configuration for pushing attendance logs to the co-hosted ZKTeco
    | integration server on port 8080 (192.168.9.243 / 127.0.0.1).
    |
    | When enabled, punches received by DTRService are automatically forwarded
    | to the ZKTeco integration server, which flags them with `downloaded = 0`
    | and serves them over the ZKTeco TCP/UDP Proxy (Port 4370) to legacy
    | desktop payroll software (e.g. ZKTime, standalone SDK / zkemkeeper.dll).
    |
    */

    // Enable or disable automatic forwarding of punches to ZKTeco server
    'forward_enabled' => env('ZK_SERVER_FORWARD_ENABLED', true),

    // Target ZKTeco Integration Server URL (use 127.0.0.1:8080 when co-hosted)
    'host' => env('ZK_SERVER_HOST', 'http://127.0.0.1:8080'),

    // HTTP timeout in seconds (short timeout prevents blocking attendance flow)
    'timeout' => env('ZK_SERVER_TIMEOUT', 3),

    // Optional API key if enabled on ZKTeco Integration Server
    'api_key' => env('ZK_SERVER_API_KEY', null),

    // Default device name/identifier sent with punches forwarded from DTRService
    'device_sn' => env('ZK_SERVER_DEVICE_SN', 'DTRSERVICE_8181'),

    // Specific device IDs permitted to forward punches to ZKTeco (e.g. '7' or '7,14,18').
    // If set, ONLY punches from these device ID(s) are forwarded.
    // If empty or '*', all active devices are permitted.
    'device_ids' => env('ZK_FORWARD_DEVICE_IDS', ''),

    // Map canonical biometric_id to hrbliz_biometric_id when forwarding to ZKTeco
    // (e.g. biometric_id 493 is forwarded to Port 8080/4370 as hrbliz_biometric_id 5180)
    'use_hrbliz_id' => env('ZK_FORWARD_USE_HRBLIZ_ID', true),

    // Enforce strictly 0 (Check-In) or 1 (Check-Out) status for HRBLIZ.
    // If incoming punch has status 255 (neutral punch from biometric hardware),
    // it is automatically classified into 0 or 1 using UMIS schedule shift windows.
    'strict_hrbliz_status' => env('ZK_STRICT_HRBLIZ_STATUS', true),

    // Batch size for console synchronization commands
    'batch_size' => env('ZK_SERVER_BATCH_SIZE', 50),
];
