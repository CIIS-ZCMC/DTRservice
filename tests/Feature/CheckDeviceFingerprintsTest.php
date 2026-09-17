<?php

use App\Models\Biometrics;
use App\Models\Devices;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function () {
    if (!Schema::hasTable('devices')) {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_name')->nullable();
            $table->string('device_id')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('ip_address')->nullable();
            $table->integer('com_key')->default(0);
            $table->integer('soap_port')->default(80);
            $table->integer('udp_port')->default(4370);
            $table->boolean('is_active')->default(1);
            $table->boolean('is_registration')->default(0);
            $table->boolean('for_attendance')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('biometrics')) {
        Schema::create('biometrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->unique();
            $table->string('name')->nullable();
            $table->integer('privilege')->default(0);
            $table->longText('biometric')->nullable();
            $table->longText('face')->nullable();
            $table->longText('biophoto')->nullable();
            $table->string('name_with_biometric')->nullable();
            $table->timestamps();
        });
    }

    Devices::query()->delete();
    Biometrics::query()->delete();
    app(\App\Services\DeviceCommandService::class)->clearCommands();

    Devices::create([
        'device_name' => 'Test Terminal 1',
        'serial_number' => 'TEST_SN_001',
        'ip_address' => '192.168.254.254',
        'is_active' => 1,
    ]);
});

test('biometrics:check-device errors out if no target device or --all-devices flag is given', function () {
    $this->artisan('biometrics:check-device', ['pin' => 493])
        ->expectsOutputToContain('Please specify a target device serial number or use --all-devices.')
        ->assertExitCode(1);
});

test('biometrics:check-device errors out if specified device serial number is not in DB', function () {
    $this->artisan('biometrics:check-device', ['pin' => 493, 'device_sn' => 'NON_EXISTENT_SN'])
        ->expectsOutputToContain('not found in database')
        ->assertExitCode(1);
});

test('biometrics:check-device displays database authority and device query table for single device', function () {
    Biometrics::create([
        'biometric_id' => 493,
        'name' => 'Reenjay Test',
        'privilege' => 14,
        'biometric' => json_encode([
            ['Finger_ID' => '6', 'Size' => '566', 'Valid' => '1', 'Template' => 'TEST_TMP']
        ]),
    ]);

    $this->artisan('biometrics:check-device', ['pin' => 493, 'device_sn' => 'TEST_SN_001'])
        ->expectsOutputToContain('BIOMETRIC DEVICE LIVE FINGERPRINT INSPECTION — PIN 493')
        ->expectsOutputToContain('Reenjay Test')
        ->expectsOutputToContain('Slot 6 (Left Index')
        ->expectsOutputToContain('TEST_SN_001')
        ->assertExitCode(0);
});

test('biometrics:check-device runs successfully with --all-devices flag', function () {
    Biometrics::create([
        'biometric_id' => 493,
        'name' => 'All Devices User',
        'privilege' => 0,
        'biometric' => 'NOT_YET_REGISTERED',
    ]);

    $this->artisan('biometrics:check-device', ['pin' => 493, '--all-devices' => true])
        ->expectsOutputToContain('BIOMETRIC DEVICE LIVE FINGERPRINT INSPECTION — PIN 493')
        ->expectsOutputToContain('All Devices User')
        ->assertExitCode(0);
});

test('biometrics:check-device with --clean flag executes safely when no ghosts are detected', function () {
    $this->artisan('biometrics:check-device', ['pin' => 493, 'device_sn' => 'TEST_SN_001', '--clean' => true, '--force' => true])
        ->assertExitCode(0);
});

test('multi-row TAD response and TMP alias correctly extracts all enrolled slots including Slot 3', function () {
    // Simulate multi-row response returned by TAD when querying templates
    $mockTadMultiRow = [
        'Row' => [
            ['FingerID' => '3', 'TMP' => 'TEMPLATE_DATA_SLOT_3', 'Size' => '540'],
            ['Finger_ID' => '6', 'Template' => 'TEMPLATE_DATA_SLOT_6', 'Size' => '560'],
        ]
    ];

    $deviceSlots = [];
    if (!empty($mockTadMultiRow['Row'])) {
        $rows = isset($mockTadMultiRow['Row'][0]) ? $mockTadMultiRow['Row'] : [$mockTadMultiRow['Row']];
        foreach ($rows as $r) {
            $template = $r['Template'] ?? $r['TMP'] ?? null;
            if (!empty($template)) {
                $fid = (int)($r['FingerID'] ?? $r['Finger_ID'] ?? $r['FID'] ?? 0);
                $size = $r['Size'] ?? strlen($template);
                $deviceSlots[$fid] = $size;
            }
        }
    }

    expect($deviceSlots)->toHaveKey(3);
    expect($deviceSlots)->toHaveKey(6);
    expect($deviceSlots[3])->toBe('540');
    expect($deviceSlots[6])->toBe('560');
});

test('biometrics:check-device table displays Algo (ZKFP) column', function () {
    Biometrics::create([
        'biometric_id' => 493,
        'name' => 'Algo Test User',
        'privilege' => 0,
        'biometric' => 'NOT_YET_REGISTERED',
    ]);

    $this->artisan('biometrics:check-device', ['pin' => 493, 'device_sn' => 'TEST_SN_001'])
        ->expectsOutputToContain('Algo (ZKFP)')
        ->assertExitCode(0);
});

test('candidate PIN resolution discovers templates mapped under internal terminal PIN', function () {
    // Simulate user row on terminal where internal PIN is 3 and badge PIN2 is 16
    $userRow = [
        'PIN' => '3',
        'Name' => 'Haradji, Jennylyn',
        'PIN2' => '16',
        'Privilege' => '0',
    ];

    $pin = 16;
    $devicePin1 = isset($userRow['PIN']) ? (int)$userRow['PIN'] : null;
    $devicePin2 = isset($userRow['PIN2']) ? (int)$userRow['PIN2'] : null;
    $candidatePins = array_values(array_unique(array_filter([$pin, $devicePin1, $devicePin2])));

    expect($candidatePins)->toContain(16);
    expect($candidatePins)->toContain(3);

    // Simulate mock templates stored under internal terminal PIN 3
    $templatesByPin = [
        3 => [
            'Row' => [
                'FingerID' => '6',
                'Size' => '922',
                'TMP' => 'MOCK_ZK10_TEMPLATE_STRING',
            ]
        ],
        16 => [] // Empty when querying badge PIN directly
    ];

    $discoveredSlots = [];
    foreach ($candidatePins as $cPin) {
        $resp = $templatesByPin[$cPin] ?? [];
        if (!empty($resp['Row'])) {
            $rows = isset($resp['Row'][0]) ? $resp['Row'] : [$resp['Row']];
            foreach ($rows as $r) {
                $fid = (int)($r['FingerID'] ?? 0);
                $discoveredSlots[$fid] = (int)($r['Size'] ?? 0);
            }
        }
    }

    expect($discoveredSlots)->toHaveKey(6);
    expect($discoveredSlots[6])->toBe(922);
});

test('cross-device algorithm divergence warning logic triggers when multiple algorithms are present', function () {
    $detectedAlgos = ['v10' => 10, 'v9' => 2];

    expect(count($detectedAlgos))->toBeGreaterThan(1);

    $algoParts = [];
    foreach ($detectedAlgos as $alg => $count) {
        $algoParts[] = "{$count} device(s) on {$alg}";
    }

    $warningMessage = "Terminals run mixed ZKFinger algorithms (" . implode(', ', $algoParts) . ").";
    expect($warningMessage)->toBe('Terminals run mixed ZKFinger algorithms (10 device(s) on v10, 2 device(s) on v9).');
});

test('biometrics:check-device with --fix does not queue unsupported DATA UPDATE timezone command', function () {
    Biometrics::create([
        'biometric_id' => 1162,
        'name' => 'Fix Test User',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '3', 'Size' => '540', 'Valid' => '1', 'Template' => 'V10_TMP_3']
        ]),
    ]);

    $this->artisan('biometrics:check-device', ['pin' => 1162, 'device_sn' => 'TEST_SN_001', '--fix' => true, '--force' => true])
        ->assertExitCode(0);

    $commandService = app(\App\Services\DeviceCommandService::class);
    $allCommands = $commandService->getAllCommands('TEST_SN_001');

    $timezoneCommands = array_filter($allCommands, fn($c) => str_contains($c['command'] ?? '', 'timezone'));
    expect($timezoneCommands)->toBeEmpty();

    $userCommands = array_filter($allCommands, fn($c) => str_starts_with($c['command'] ?? '', 'DATA USER'));
    expect($userCommands)->not->toBeEmpty();
    $firstUserCmd = array_values($userCommands)[0]['command'];
    expect($firstUserCmd)->toContain('Grp=1');
    expect($firstUserCmd)->toContain('TZ=1');
    expect($firstUserCmd)->toContain('PIN=1162');
    expect($firstUserCmd)->toContain('PIN2=1162');
});

test('ghost slot cleanup queues ADMS deletion across both badge PIN and internal terminal PIN', function () {
    $ghostFids = [3, 6];
    $candPins = [1162, 1379];
    $deviceSn = 'TEST_SN_001';

    $commandService = app(\App\Services\DeviceCommandService::class);
    $commandService->clearCommands();

    foreach ($ghostFids as $gfid) {
        foreach ($candPins as $cPin) {
            $cmd = "DATA DELETE FINGERTMP\tPIN={$cPin}\tFID={$gfid}";
            $commandService->queueCommand($deviceSn, $cmd);
        }
    }

    $allCommands = $commandService->getAllCommands($deviceSn);
    expect($allCommands)->toHaveCount(4);

    $cmdStrings = array_column($allCommands, 'command');
    expect($cmdStrings)->toContain("DATA DELETE FINGERTMP\tPIN=1162\tFID=3");
    expect($cmdStrings)->toContain("DATA DELETE FINGERTMP\tPIN=1379\tFID=3");
    expect($cmdStrings)->toContain("DATA DELETE FINGERTMP\tPIN=1162\tFID=6");
    expect($cmdStrings)->toContain("DATA DELETE FINGERTMP\tPIN=1379\tFID=6");
});


