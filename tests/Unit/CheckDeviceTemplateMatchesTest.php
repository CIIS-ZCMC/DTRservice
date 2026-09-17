<?php

use App\Models\Biometrics;
use App\Models\Devices;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

uses(Tests\TestCase::class);

beforeEach(function () {
    if (!Schema::hasTable('biometrics')) {
        Schema::create('biometrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->unique();
            $table->string('name')->nullable();
            $table->integer('privilege')->default(0);
            $table->longText('biometric')->nullable();
            $table->string('name_with_biometric')->nullable();
            $table->timestamps();
        });
    }

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
            $table->string('fp_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_cleared_at')->nullable();
            $table->timestamps();
        });
    }

    // Clean up test PINs before each test
    Biometrics::whereIn('biometric_id', [99991, 99992, 99993, 1162, 8084])->delete();
});

afterEach(function () {
    Biometrics::whereIn('biometric_id', [99991, 99992, 99993, 1162, 8084])->delete();
});

test('findMatchesForTemplates returns empty array when given empty templates', function () {
    $matches = Biometrics::findMatchesForTemplates([]);
    expect($matches)->toBeEmpty();
});

test('findMatchesForTemplates finds identical matching template (e.g. PIN 1162 slot 9 matching PIN 8084)', function () {
    $sharedTemplate = 'BASE64_FINGERPRINT_DATA_FOR_SLOT_9_IDENTICAL_BETWEEN_1162_AND_8084';

    // PIN 8084 enrolled in database with slot 9
    Biometrics::create([
        'biometric_id' => 8084,
        'name' => 'Maria Santos',
        'privilege' => 0,
        'biometric' => json_encode([
            [
                'Finger_ID' => '9',
                'Size' => '512',
                'Valid' => '1',
                'Template' => $sharedTemplate,
            ],
        ]),
    ]);

    // Extracted templates for PIN 1162 (e.g. from physical device terminal slot 9)
    $targetTemplates = [
        [
            'finger_id' => '9',
            'finger_name' => 'Left Little',
            'template' => $sharedTemplate,
            'size' => 512,
            'version' => 'v10',
            'source' => 'Device: Lobby Bio 1',
            'device_sn' => 'UCR6254000009',
            'device_name' => 'Lobby Bio 1',
            'is_ghost' => true,
        ],
    ];

    $matches = Biometrics::findMatchesForTemplates($targetTemplates, 1162);

    expect($matches)->toHaveCount(1);
    expect($matches[0]['target_finger_id'])->toBe('9');
    expect($matches[0]['target_finger_name'])->toBe('Left Little');
    expect($matches[0]['matched_pin'])->toBe(8084);
    expect($matches[0]['matched_name'])->toBe('Maria Santos');
    expect($matches[0]['matched_finger_id'])->toBe('9');
    expect($matches[0]['is_same_finger_slot'])->toBeTrue();
    expect($matches[0]['is_ghost_on_device'])->toBeTrue();
    expect($matches[0]['match_type'])->toBe('EXACT_TEMPLATE_IDENTICAL');
});

test('findMatchesForTemplates handles cross-slot duplicate matching (PIN 1162 slot 9 matching PIN 8084 slot 1)', function () {
    $sharedTemplate = 'CROSS_SLOT_SHARED_FINGERPRINT_TEMPLATE_9_TO_1';

    Biometrics::create([
        'biometric_id' => 8084,
        'name' => 'Pedro Penduko',
        'privilege' => 0,
        'biometric' => json_encode([
            [
                'Finger_ID' => '1', // Slot 1 Right Index on 8084
                'Size' => '512',
                'Valid' => '1',
                'Template' => $sharedTemplate,
            ],
        ]),
    ]);

    $targetTemplates = [
        [
            'finger_id' => '9', // Slot 9 Left Little on 1162
            'finger_name' => 'Left Little',
            'template' => $sharedTemplate,
            'size' => 512,
            'version' => 'v10',
            'source' => 'Device: ER Terminal',
            'is_ghost' => false,
        ],
    ];

    $matches = Biometrics::findMatchesForTemplates($targetTemplates, 1162);

    expect($matches)->toHaveCount(1);
    expect($matches[0]['target_finger_id'])->toBe('9');
    expect($matches[0]['matched_pin'])->toBe(8084);
    expect($matches[0]['matched_finger_id'])->toBe('1');
    expect($matches[0]['is_same_finger_slot'])->toBeFalse();
});

test('findMatchesForTemplates excludes target PIN itself from match results', function () {
    $template = 'TEMPLATE_OF_TARGET_USER_ONLY';

    Biometrics::create([
        'biometric_id' => 99991,
        'name' => 'Target Employee',
        'privilege' => 0,
        'biometric' => json_encode([
            [
                'Finger_ID' => '9',
                'Size' => '512',
                'Valid' => '1',
                'Template' => $template,
            ],
        ]),
    ]);

    $targetTemplates = [
        [
            'finger_id' => '9',
            'finger_name' => 'Left Little',
            'template' => $template,
            'size' => 512,
            'version' => 'v10',
            'source' => 'Device: Lobby Bio 1',
        ],
    ];

    // Exclude 99991
    $matches = Biometrics::findMatchesForTemplates($targetTemplates, 99991);
    expect($matches)->toBeEmpty();
});

test('biometrics:check-device-match artisan command outputs json with duplicate detection', function () {
    $sharedTemplate = 'IDENTICAL_TEMPLATE_CLI_TEST';

    Biometrics::create([
        'biometric_id' => 99991,
        'name' => 'Target Juan',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '9', 'Size' => '512', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 99992,
        'name' => 'Matched Maria',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '9', 'Size' => '512', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    $this->artisan('biometrics:check-device-match', [
        'pin' => 99991,
        '--db-only' => true,
        '--json' => true,
    ])
    ->assertExitCode(0);
});

test('biometrics:check-device-match outputs success when templates are unique', function () {
    Biometrics::create([
        'biometric_id' => 99991,
        'name' => 'Unique Juan',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '9', 'Size' => '512', 'Valid' => '1', 'Template' => 'UNIQUE_TEMPLATE_AAA'],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 99992,
        'name' => 'Unique Maria',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '9', 'Size' => '512', 'Valid' => '1', 'Template' => 'UNIQUE_TEMPLATE_BBB'],
        ]),
    ]);

    $this->artisan('biometrics:check-device-match', [
        'pin' => 99991,
        '--db-only' => true,
    ])
    ->expectsOutputToContain('SUCCESS: No identical fingerprint templates found across other employee PINs')
    ->assertExitCode(0);
});

test('biometrics:check-device-match detects identical match when slot 9 matches another PIN', function () {
    $sharedTemplate = 'SHARED_TEMPLATE_99991_99992_SLOT_9';

    Biometrics::create([
        'biometric_id' => 99991,
        'name' => 'Juan Dela Cruz',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '9', 'Size' => '512', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 99992,
        'name' => 'Maria Makiling',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '9', 'Size' => '512', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    $this->artisan('biometrics:check-device-match', [
        'pin' => 99991,
        '--db-only' => true,
    ])
    ->expectsOutputToContain('Target Employee Details:')
    ->expectsOutputToContain('99991')
    ->expectsOutputToContain('IDENTICAL FINGERPRINT TEMPLATE(S) DETECTED ACROSS PINS')
    ->expectsOutputToContain('99992')
    ->assertExitCode(0);
});

test('biometrics:check-device-match with --compare-pin compares against specific PIN', function () {
    $sharedTemplate = 'DIRECT_COMPARE_SHARED_TEMPLATE';

    Biometrics::create([
        'biometric_id' => 99991,
        'name' => 'Employee 99991',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '9', 'Size' => '512', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 99992,
        'name' => 'Employee 99992',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '9', 'Size' => '512', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    $this->artisan('biometrics:check-device-match', [
        'pin' => 99991,
        '--compare-pin' => 99992,
        '--db-only' => true,
    ])
    ->expectsOutputToContain('PIN 99992')
    ->expectsOutputToContain('IDENTICAL')
    ->assertExitCode(0);
});
