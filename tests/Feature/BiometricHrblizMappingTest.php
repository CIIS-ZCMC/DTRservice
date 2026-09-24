<?php

use App\Models\Biometrics;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (!Schema::hasTable('biometrics')) {
        Schema::create('biometrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->unique();
            $table->integer('hrbliz_biometric_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->integer('privilege')->default(0);
            $table->longText('biometric')->nullable();
            $table->longText('face')->nullable();
            $table->longText('biophoto')->nullable();
            $table->string('name_with_biometric')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasColumn('biometrics', 'hrbliz_biometric_id')) {
        Schema::table('biometrics', function (Blueprint $table) {
            $table->integer('hrbliz_biometric_id')->nullable()->index()->after('biometric_id');
        });
    }
});

test('hrbliz mapping view renders successfully', function () {
    $response = $this->get('/biometrics/hrbliz');
    $response->assertStatus(200);
    $response->assertSee('HRBLIZ Biometrics ID Management', false);
    $response->assertSee('Side-by-Side Merge & Verification', false);
    $response->assertSee('Manual Biometrics Manager', false);
});

test('hrbliz list endpoint returns paginated biometrics records and statistics', function () {
    Biometrics::firstOrCreate(
        ['biometric_id' => 99101],
        ['name' => 'Test Employee One', 'hrbliz_biometric_id' => null]
    );

    Biometrics::firstOrCreate(
        ['biometric_id' => 99102],
        ['name' => 'Test Employee Two', 'hrbliz_biometric_id' => 7702]
    );

    $response = $this->getJson('/biometrics/hrbliz/list?per_page=10');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            'stats' => ['total_system', 'total_mapped', 'total_unmapped'],
        ]);

    expect($response->json('success'))->toBeTrue();
});

test('hrbliz update endpoint updates single hrbliz_biometric_id', function () {
    $emp = Biometrics::firstOrCreate(
        ['biometric_id' => 99201],
        ['name' => 'Carreon, Manuel', 'hrbliz_biometric_id' => null]
    );

    $response = $this->postJson('/biometrics/hrbliz/update', [
        'biometric_id' => 99201,
        'hrbliz_biometric_id' => 472,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'biometric_id' => 99201,
            'hrbliz_biometric_id' => 472,
        ]);

    expect($emp->fresh()->hrbliz_biometric_id)->toBe(472);

    // Test clearing the ID
    $clearRes = $this->postJson('/biometrics/hrbliz/update', [
        'biometric_id' => 99201,
        'hrbliz_biometric_id' => null,
    ]);

    $clearRes->assertStatus(200);
    expect($emp->fresh()->hrbliz_biometric_id)->toBeNull();
});

test('hrbliz analyze endpoint parses csv and fuzzy-matches against biometrics', function () {
    Biometrics::firstOrCreate(
        ['biometric_id' => 99301],
        ['name' => 'Abutazil, Mohammad', 'hrbliz_biometric_id' => null]
    );
    Biometrics::firstOrCreate(
        ['biometric_id' => 99302],
        ['name' => 'Amit, Tristan Jay', 'hrbliz_biometric_id' => null]
    );

    $csv = "AC No.,No.,Name\n1003,,\"ABUTAZIL, MOHAMMAD\"\n5182,,\"AMIT, TRISTAN JAY\"\n9999,,\"NONEXISTENT PERSON\"";

    $response = $this->postJson('/biometrics/hrbliz/analyze', [
        'raw_csv' => $csv,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'message',
            'summary' => [
                'total_rows',
                'matched_count',
                'exact_count',
                'unmatched_count',
            ],
            'results',
        ]);

    $results = $response->json('results');
    expect($results)->toHaveCount(3);

    // Abutazil match
    $abutazil = collect($results)->firstWhere('excel_ac_no', 1003);
    expect($abutazil)->not->toBeNull();
    expect($abutazil['status'])->toBe('exact');
    expect($abutazil['matched_biometric']['biometric_id'])->toBe(99301);

    // Amit match
    $amit = collect($results)->firstWhere('excel_ac_no', 5182);
    expect($amit)->not->toBeNull();
    expect($amit['status'])->toBe('exact');
    expect($amit['matched_biometric']['biometric_id'])->toBe(99302);

    // Unmatched
    $unknown = collect($results)->firstWhere('excel_ac_no', 9999);
    expect($unknown)->not->toBeNull();
    expect($unknown['status'])->toBe('unmatched');
});

test('hrbliz batch-merge endpoint merges confirmed mappings', function () {
    $emp1 = Biometrics::firstOrCreate(
        ['biometric_id' => 99401],
        ['name' => 'Batch Test One', 'hrbliz_biometric_id' => null]
    );
    $emp2 = Biometrics::firstOrCreate(
        ['biometric_id' => 99402],
        ['name' => 'Batch Test Two', 'hrbliz_biometric_id' => null]
    );

    $response = $this->postJson('/biometrics/hrbliz/batch-merge', [
        'mappings' => [
            ['biometric_id' => 99401, 'hrbliz_biometric_id' => 5501],
            ['biometric_id' => 99402, 'hrbliz_biometric_id' => 5502],
        ],
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'updated_count' => 2,
        ]);

    expect($emp1->fresh()->hrbliz_biometric_id)->toBe(5501);
    expect($emp2->fresh()->hrbliz_biometric_id)->toBe(5502);
});

test('hrbliz candidates search endpoint returns matching biometric records', function () {
    Biometrics::firstOrCreate(
        ['biometric_id' => 99501],
        ['name' => 'Ferdinand Marcos Test', 'hrbliz_biometric_id' => null]
    );

    $response = $this->getJson('/biometrics/hrbliz/candidates?q=Ferdinand');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'candidates']);

    $candidates = $response->json('candidates');
    expect($candidates)->not->toBeEmpty();
    expect(collect($candidates)->firstWhere('biometric_id', 99501))->not->toBeNull();
});
