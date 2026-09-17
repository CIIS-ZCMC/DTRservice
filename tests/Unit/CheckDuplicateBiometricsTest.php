<?php

use App\Models\Biometrics;
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

    // Clean up test PINs before each test
    Biometrics::whereIn('biometric_id', [2479, 1052, 9999, 8888, 7777])->delete();
});

afterEach(function () {
    Biometrics::whereIn('biometric_id', [2479, 1052, 9999, 8888, 7777])->delete();
});

test('findDuplicateTemplates returns error if target PIN does not exist', function () {
    $result = Biometrics::findDuplicateTemplates(2479);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('2479 was not found');
});

test('findDuplicateTemplates returns clean status when target has no templates', function () {
    Biometrics::create([
        'biometric_id' => 2479,
        'name' => 'Juan Dela Cruz',
        'privilege' => 0,
        'biometric' => 'NOT_YET_REGISTERED',
    ]);

    $result = Biometrics::findDuplicateTemplates(2479);

    expect($result['success'])->toBeTrue();
    expect($result['enrolled_templates_count'])->toBe(0);
    expect($result['has_duplicates'])->toBeFalse();
    expect($result['duplicates'])->toBeEmpty();
});

test('findDuplicateTemplates returns no duplicates when all enrolled templates are unique', function () {
    Biometrics::create([
        'biometric_id' => 2479,
        'name' => 'Juan Dela Cruz',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '512', 'Valid' => '1', 'Template' => 'TMP_UNIQUE_A'],
            ['Finger_ID' => '1', 'Size' => '512', 'Valid' => '1', 'Template' => 'TMP_UNIQUE_B'],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 1052,
        'name' => 'Maria Santos',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '512', 'Valid' => '1', 'Template' => 'TMP_OTHER_X'],
            ['Finger_ID' => '1', 'Size' => '512', 'Valid' => '1', 'Template' => 'TMP_OTHER_Y'],
        ]),
    ]);

    $result = Biometrics::findDuplicateTemplates(2479);

    expect($result['success'])->toBeTrue();
    expect($result['has_duplicates'])->toBeFalse();
    expect($result['duplicates_count'])->toBe(0);
    expect($result['duplicates'])->toBeEmpty();
});

test('findDuplicateTemplates detects identical template on same finger slot across different PINs', function () {
    $sharedTemplate = 'SIlTUzIxAAAByskECAUHCc7QAADpyukAAAAAgXcNZMpiAOoPUACoAGHFywB7ABEPsQCOylw';

    Biometrics::create([
        'biometric_id' => 2479,
        'name' => 'Juan Dela Cruz',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '512', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 1052,
        'name' => 'Duplicate Employee',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '512', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    $result = Biometrics::findDuplicateTemplates(2479);

    expect($result['success'])->toBeTrue();
    expect($result['has_duplicates'])->toBeTrue();
    expect($result['duplicates_count'])->toBe(1);

    $dup = $result['duplicates'][0];
    expect($dup['target_pin'])->toBe(2479);
    expect($dup['matched_pin'])->toBe(1052);
    expect($dup['matched_name'])->toBe('Duplicate Employee');
    expect($dup['target_finger_id'])->toBe('0');
    expect($dup['matched_finger_id'])->toBe('0');
    expect($dup['is_same_finger_slot'])->toBeTrue();
    expect($dup['match_type'])->toBe('EXACT_TEMPLATE_IDENTICAL');
});

test('findDuplicateTemplates detects cross-slot identical template between different finger IDs', function () {
    $sharedTemplate = 'SoVTUzIxAAADxsQECAUHCc7QAADrxnEBAAAAg2sfosaGAIwPOgBWAAPJQwC0AHQPaQC1xh';

    // PIN 2479 registered this fingerprint under Slot 3 (Right Ring)
    Biometrics::create([
        'biometric_id' => 2479,
        'name' => 'Juan Dela Cruz',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '3', 'Size' => '612', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    // PIN 9999 registered the exact same fingerprint under Slot 1 (Right Index)
    Biometrics::create([
        'biometric_id' => 9999,
        'name' => 'Cross Slot User',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '1', 'Size' => '612', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    $result = Biometrics::findDuplicateTemplates(2479);

    expect($result['success'])->toBeTrue();
    expect($result['has_duplicates'])->toBeTrue();
    expect($result['duplicates_count'])->toBe(1);

    $dup = $result['duplicates'][0];
    expect($dup['target_pin'])->toBe(2479);
    expect($dup['target_finger_id'])->toBe('3');
    expect($dup['matched_pin'])->toBe(9999);
    expect($dup['matched_finger_id'])->toBe('1');
    expect($dup['is_same_finger_slot'])->toBeFalse();
});

test('findDuplicateTemplates detects internal duplicates where user enrolled same finger on multiple slots', function () {
    $duplicateTemplate = 'INTERNAL_SHARED_TEMPLATE_12345';

    Biometrics::create([
        'biometric_id' => 2479,
        'name' => 'Same User Multi Slot',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '500', 'Valid' => '1', 'Template' => $duplicateTemplate],
            ['Finger_ID' => '5', 'Size' => '500', 'Valid' => '1', 'Template' => $duplicateTemplate],
        ]),
    ]);

    $result = Biometrics::findDuplicateTemplates(2479);

    expect($result['has_duplicates'])->toBeTrue();
    expect($result['internal_duplicates'])->toHaveCount(1);
    expect($result['internal_duplicates'][0]['finger_id_1'])->toBe('0');
    expect($result['internal_duplicates'][0]['finger_id_2'])->toBe('5');
    expect($result['duplicates_count'])->toBe(0); // No other PINs, only internal
});

test('findAllDuplicateTemplates clusters identical templates across the whole table', function () {
    $shared1 = 'GLOBAL_SHARED_TEMPLATE_1';
    $shared2 = 'GLOBAL_SHARED_TEMPLATE_2';

    Biometrics::create([
        'biometric_id' => 2479,
        'name' => 'User One',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '500', 'Valid' => '1', 'Template' => $shared1],
            ['Finger_ID' => '1', 'Size' => '500', 'Valid' => '1', 'Template' => $shared2],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 1052,
        'name' => 'User Two',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '500', 'Valid' => '1', 'Template' => $shared1],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 9999,
        'name' => 'User Three',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '2', 'Size' => '500', 'Valid' => '1', 'Template' => $shared2],
        ]),
    ]);

    $clusters = Biometrics::findAllDuplicateTemplates();

    // Both shared1 and shared2 should be detected as clusters with >1 PIN
    $hashes = array_column($clusters, 'template_hash');
    expect($hashes)->toContain(md5($shared1), md5($shared2));
});

test('API endpoint GET /api/biometrics/{pin}/duplicates returns duplicate details', function () {
    $sharedTemplate = 'API_TEST_SHARED_TEMPLATE';

    Biometrics::create([
        'biometric_id' => 2479,
        'name' => 'Target API User',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '400', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 1052,
        'name' => 'Duplicate API User',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '400', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    $response = $this->getJson('/api/biometrics/2479/duplicates');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'target_pin' => 2479,
            'has_duplicates' => true,
            'duplicates_count' => 1,
        ]);

    $data = $response->json();
    expect($data['duplicates'][0]['matched_pin'])->toBe(1052);
});

test('API endpoint GET /api/biometrics/check-duplicates with pin query param works', function () {
    Biometrics::create([
        'biometric_id' => 2479,
        'name' => 'Query Param User',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '400', 'Valid' => '1', 'Template' => 'NON_DUPLICATE_SAMPLE'],
        ]),
    ]);

    $response = $this->getJson('/api/biometrics/check-duplicates?pin=2479');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'target_pin' => 2479,
            'has_duplicates' => false,
            'duplicates_count' => 0,
        ]);
});

test('Artisan command biometrics:find-duplicates displays duplicate warnings and table', function () {
    $sharedTemplate = 'ARTISAN_TEST_SHARED_TEMPLATE';

    Biometrics::create([
        'biometric_id' => 2479,
        'name' => 'Artisan User A',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '450', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    Biometrics::create([
        'biometric_id' => 1052,
        'name' => 'Artisan User B',
        'privilege' => 0,
        'biometric' => json_encode([
            ['Finger_ID' => '0', 'Size' => '450', 'Valid' => '1', 'Template' => $sharedTemplate],
        ]),
    ]);

    $this->artisan('biometrics:find-duplicates 2479')
        ->expectsOutputToContain('Target Employee Details:')
        ->expectsOutputToContain('2479')
        ->expectsOutputToContain('IDENTICAL TEMPLATE(S) FOUND ACROSS OTHER PINs!')
        ->expectsOutputToContain('1052')
        ->assertExitCode(0);
});
