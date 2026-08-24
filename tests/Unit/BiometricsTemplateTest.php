<?php

use App\Models\Biometrics;
use App\Services\ZkPushParser;
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
});

test('saveFingerprintTemplate appends new FID to user with existing templates', function () {
    $initialTemplates = [
        ['Finger_ID' => '5', 'Size' => '972', 'Valid' => '1', 'Template' => 'TMP_5'],
        ['Finger_ID' => '6', 'Size' => '782', 'Valid' => '1', 'Template' => 'TMP_6'],
        ['Finger_ID' => '7', 'Size' => '916', 'Valid' => '1', 'Template' => 'TMP_7'],
        ['Finger_ID' => '9', 'Size' => '760', 'Valid' => '1', 'Template' => 'TMP_9'],
    ];

    $user = Biometrics::create([
        'biometric_id' => 99493,
        'name' => 'Test User',
        'privilege' => 1,
        'biometric' => json_encode($initialTemplates),
    ]);

    Biometrics::saveFingerprintTemplate(99493, '3', '612', '1', 'TMP_3_NEW');

    $user->refresh();
    $templates = json_decode($user->biometric, true);

    expect($templates)->toHaveCount(5);
    $fids = array_column($templates, 'Finger_ID');
    expect($fids)->toContain('5', '6', '7', '9', '3');

    $fid3 = collect($templates)->firstWhere('Finger_ID', '3');
    expect($fid3['Size'])->toBe('612');
    expect($fid3['Valid'])->toBe('1');
    expect($fid3['Template'])->toBe('TMP_3_NEW');

    $user->delete();
});

test('saveFingerprintTemplate updates existing FID without creating duplicates', function () {
    $initialTemplates = [
        ['Finger_ID' => '3', 'Size' => '500', 'Valid' => '1', 'Template' => 'TMP_OLD'],
        ['Finger_ID' => '5', 'Size' => '972', 'Valid' => '1', 'Template' => 'TMP_5'],
    ];

    $user = Biometrics::create([
        'biometric_id' => 99494,
        'name' => 'Test User Duplicate',
        'privilege' => 0,
        'biometric' => json_encode($initialTemplates),
    ]);

    Biometrics::saveFingerprintTemplate(99494, '3', '612', '1', 'TMP_UPDATED');

    $user->refresh();
    $templates = json_decode($user->biometric, true);

    expect($templates)->toHaveCount(2);
    $fid3 = collect($templates)->firstWhere('Finger_ID', '3');
    expect($fid3['Size'])->toBe('612');
    expect($fid3['Template'])->toBe('TMP_UPDATED');

    $user->delete();
});

test('if device sends existing FID 9 for PIN 493, it overrides FID 9 in-place with new template', function () {
    $initialTemplates = [
        ['Finger_ID' => '3', 'Size' => '612', 'Valid' => '1', 'Template' => 'TMP_3_EXISTING'],
        ['Finger_ID' => '5', 'Size' => '972', 'Valid' => '1', 'Template' => 'TMP_5_EXISTING'],
        ['Finger_ID' => '6', 'Size' => '782', 'Valid' => '1', 'Template' => 'TMP_6_EXISTING'],
        ['Finger_ID' => '7', 'Size' => '916', 'Valid' => '1', 'Template' => 'TMP_7_EXISTING'],
        ['Finger_ID' => '9', 'Size' => '760', 'Valid' => '1', 'Template' => 'TMP_9_OLD_TEMPLATE'],
    ];

    $user = Biometrics::create([
        'biometric_id' => 99493,
        'name' => 'Caimor, Reenjay',
        'privilege' => 1,
        'biometric' => json_encode($initialTemplates),
    ]);

    // Device pushes re-enrolled FID 9 with new template and new size
    Biometrics::saveFingerprintTemplate(99493, '9', '1008', '1', 'TMP_9_NEWLY_OVERRIDDEN');

    $user->refresh();
    $templates = json_decode($user->biometric, true);

    // Total count must still be 5 (not 6)
    expect($templates)->toHaveCount(5);

    // Verify all FIDs present without duplicate 9s
    $fids = array_column($templates, 'Finger_ID');
    expect($fids)->toEqual(['3', '5', '6', '7', '9']);
    expect(array_count_values($fids)['9'])->toBe(1);

    // Verify FID 9 was overridden with the new template and size
    $fid9 = collect($templates)->firstWhere('Finger_ID', '9');
    expect($fid9['Size'])->toBe('1008');
    expect($fid9['Template'])->toBe('TMP_9_NEWLY_OVERRIDDEN');
    expect($fid9['Valid'])->toBe('1');

    // Verify other fingers remained untouched
    $fid3 = collect($templates)->firstWhere('Finger_ID', '3');
    expect($fid3['Template'])->toBe('TMP_3_EXISTING');

    $user->delete();
});

test('saveFingerprintTemplate replaces NOT_YET_REGISTERED cleanly', function () {
    $user = Biometrics::create([
        'biometric_id' => 99495,
        'name' => 'Unregistered User',
        'privilege' => 0,
        'biometric' => 'NOT_YET_REGISTERED',
    ]);

    Biometrics::saveFingerprintTemplate(99495, '6', '800', '1', 'TMP_FIRST');

    $user->refresh();
    $templates = json_decode($user->biometric, true);

    expect(is_array($templates))->toBeTrue();
    expect($templates)->toHaveCount(1);
    expect($templates[0]['Finger_ID'])->toBe('6');
    expect($templates[0]['Size'])->toBe('800');
    expect($templates[0]['Template'])->toBe('TMP_FIRST');

    $user->delete();
});

test('ZkPushParser parses FP PIN lines and normalizes aliases', function () {
    $line = "FP PIN=493\tFID=3\tSize=612\tValid=1\tTMP=SIlTUzIxAAAByskECAU";
    $records = ZkPushParser::parseKeyValues($line);

    expect($records)->toHaveCount(1);
    $rec = $records[0];

    expect($rec['PIN'])->toBe('493');
    expect($rec['Finger_ID'])->toBe('3');
    expect($rec['FID'])->toBe('3');
    expect($rec['Size'])->toBe('612');
    expect($rec['Valid'])->toBe('1');
    expect($rec['Template'])->toBe('SIlTUzIxAAAByskECAU');
    expect($rec['TMP'])->toBe('SIlTUzIxAAAByskECAU');
});

test('ZkPushParser detects biometric template lines accurately', function () {
    expect(ZkPushParser::isBiometricTemplateLine("FP PIN=493\tFID=3\tSize=612\tValid=1\tTMP=abc"))->toBeTrue();
    expect(ZkPushParser::isBiometricTemplateLine("PIN=493\tFingerID=3\tSize=612\tValid=1\tTemplate=abc"))->toBeTrue();
    expect(ZkPushParser::isBiometricTemplateLine("BIODATA PIN=493\tNo=0\tTMP=abc"))->toBeTrue();
    expect(ZkPushParser::isBiometricTemplateLine("493\t2026-08-24 08:00:00\t0"))->toBeFalse();
    expect(ZkPushParser::isBiometricTemplateLine("OPLOG 101\t493\t2026-08-24 08:00:00\t255"))->toBeFalse();
});

test('saveFingerprintTemplate writes custom registration verification audit logs', function () {
    $user = Biometrics::create([
        'biometric_id' => 99496,
        'name' => 'Audit Log User',
        'privilege' => 0,
        'biometric' => 'NOT_YET_REGISTERED',
    ]);

    Biometrics::saveFingerprintTemplate(
        99496,
        '2',
        '650',
        '1',
        'TMP_AUDIT_VERIFIED',
        '192.168.5.171',
        'TEST_DEV_SN',
        5
    );

    // Verify registration log file exists and contains entry
    $today = now()->format('Y-m-d');
    $auditFile = storage_path("logs/registration_verified_{$today}.txt");
    expect(file_exists($auditFile))->toBeTrue();

    $content = file_get_contents($auditFile);
    expect($content)->toContain('PIN=99496');
    expect($content)->toContain('FID=2');
    expect($content)->toContain('REPLACED_NOT_YET_REGISTERED');

    $user->delete();
});

test('removeFingerprintTemplate removes specific FID and updates database', function () {
    $templates = [
        ['Finger_ID' => '3', 'Size' => '612', 'Valid' => '1', 'Template' => 'TMP_3'],
        ['Finger_ID' => '9', 'Size' => '1008', 'Valid' => '1', 'Template' => 'TMP_9'],
    ];

    $user = Biometrics::create([
        'biometric_id' => 99333,
        'name' => 'Delete Finger User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    // Delete Finger 9 without syncing devices during unit test
    $result = Biometrics::removeFingerprintTemplate(99333, '9', false);

    expect($result)->toBeTrue();

    $user->refresh();
    $remaining = json_decode($user->biometric, true);

    expect($remaining)->toHaveCount(1);
    expect($remaining[0]['Finger_ID'])->toBe('3');

    $user->delete();
});
