<?php

use App\Contracts\LogsRepositoryInterface;
use App\Models\DeviceLogs;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function () {
    if (!Schema::hasTable('device_logs')) {
        Schema::create('device_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->nullable();
            $table->string('name')->nullable();
            $table->string('dtr_date')->nullable();
            $table->string('date_time')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_Shifting')->default(0);
            $table->string('schedule')->nullable();
            $table->boolean('active')->default(1);
            $table->string('device_name')->nullable();
            $table->timestamps();
        });
    }

    // Clean test records created during test
    DeviceLogs::where('name', 'LIKE', 'PRUNE_TEST_%')->delete();
});

afterEach(function () {
    DeviceLogs::where('name', 'LIKE', 'PRUNE_TEST_%')->delete();
});

test('pruneLogs deletes only records older than cutoff date and preserves recent logs', function () {
    $cutoff = now()->subYear()->format('Y-m-d'); // 1 year ago

    // Old records (> 1 year ago)
    $old1 = DeviceLogs::create([
        'name' => 'PRUNE_TEST_OLD_1',
        'biometric_id' => 99001,
        'dtr_date' => now()->subYear()->subDays(10)->format('Y-m-d'),
        'date_time' => now()->subYear()->subDays(10)->format('Y-m-d H:i:s'),
        'status' => '255',
    ]);

    $old2 = DeviceLogs::create([
        'name' => 'PRUNE_TEST_OLD_2',
        'biometric_id' => 99002,
        'dtr_date' => now()->subYear()->subDays(30)->format('Y-m-d'),
        'date_time' => now()->subYear()->subDays(30)->format('Y-m-d H:i:s'),
        'status' => '255',
    ]);

    // Recent records (< 1 year ago)
    $recent = DeviceLogs::create([
        'name' => 'PRUNE_TEST_RECENT',
        'biometric_id' => 99003,
        'dtr_date' => now()->subMonths(6)->format('Y-m-d'),
        'date_time' => now()->subMonths(6)->format('Y-m-d H:i:s'),
        'status' => '255',
    ]);

    $repo = app(LogsRepositoryInterface::class);
    $result = $repo->pruneLogs($cutoff, 1000, false, false);

    expect($result['deleted_count'])->toBeGreaterThanOrEqual(2)
        ->and(DeviceLogs::find($old1->id))->toBeNull()
        ->and(DeviceLogs::find($old2->id))->toBeNull()
        ->and(DeviceLogs::find($recent->id))->not->toBeNull();
});

test('pruneLogs in dry_run mode counts eligible logs without deleting anything', function () {
    $cutoff = now()->subYear()->format('Y-m-d');

    $old = DeviceLogs::create([
        'name' => 'PRUNE_TEST_DRYRUN',
        'biometric_id' => 99004,
        'dtr_date' => now()->subYear()->subDays(5)->format('Y-m-d'),
        'date_time' => now()->subYear()->subDays(5)->format('Y-m-d H:i:s'),
        'status' => '255',
    ]);

    $repo = app(LogsRepositoryInterface::class);
    $result = $repo->pruneLogs($cutoff, 1000, true, false);

    expect($result['dry_run'])->toBeTrue()
        ->and($result['total_eligible'])->toBeGreaterThanOrEqual(1)
        ->and($result['deleted_count'])->toBe(0)
        ->and(DeviceLogs::find($old->id))->not->toBeNull();
});

test('pruneLogs with archive exports gzipped records to storage/app/archive', function () {
    $cutoff = now()->subYear()->format('Y-m-d');

    $old = DeviceLogs::create([
        'name' => 'PRUNE_TEST_ARCHIVE',
        'biometric_id' => 99005,
        'dtr_date' => now()->subYear()->subDays(15)->format('Y-m-d'),
        'date_time' => now()->subYear()->subDays(15)->format('Y-m-d H:i:s'),
        'status' => '255',
    ]);

    $repo = app(LogsRepositoryInterface::class);
    $result = $repo->pruneLogs($cutoff, 1000, false, true);

    expect($result['archive_file'])->not->toBeNull()
        ->and($result['archived_count'])->toBeGreaterThanOrEqual(1);

    $archivePath = storage_path('app/archive/' . $result['archive_file']);
    expect(file_exists($archivePath))->toBeTrue();

    // Verify file is readable gzip containing archived JSON
    $gz = gzopen($archivePath, 'r');
    $content = '';
    while (!gzeof($gz)) {
        $content .= gzread($gz, 4096);
    }
    gzclose($gz);

    expect($content)->toContain('PRUNE_TEST_ARCHIVE');

    // Clean up archive file
    @unlink($archivePath);
});

test('device-logs:prune console command runs successfully with dry-run', function () {
    $this->artisan('device-logs:prune', [
        '--dry-run' => true,
        '--years' => 1,
    ])->assertSuccessful();
});

test('device-logs:prune console command prunes with --days and --force', function () {
    $old = DeviceLogs::create([
        'name' => 'PRUNE_TEST_DAYS',
        'biometric_id' => 99006,
        'dtr_date' => now()->subDays(400)->format('Y-m-d'),
        'date_time' => now()->subDays(400)->format('Y-m-d H:i:s'),
        'status' => '255',
    ]);

    $this->artisan('device-logs:prune', [
        '--days' => 365,
        '--force' => true,
    ])->assertSuccessful();

    expect(DeviceLogs::find($old->id))->toBeNull();
});

test('API endpoint POST /api/device-logs/prune returns json response in dry-run mode', function () {
    $response = $this->postJson('/api/device-logs/prune', [
        'years' => 1,
        'dry_run' => true,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'dry_run' => true,
            ],
        ]);
});

test('pruneLogs throws exception if cutoff date is newer than 1 year before today', function () {
    $repo = app(LogsRepositoryInterface::class);
    $illegalCutoff = now()->subMonths(6)->format('Y-m-d'); // 6 months is newer than 1 year

    expect(fn() => $repo->pruneLogs($illegalCutoff))
        ->toThrow(\InvalidArgumentException::class);
});

test('device-logs:prune command fails if cutoff date is newer than 1 year before today', function () {
    $illegalDate = now()->subMonths(3)->format('Y-m-d');

    $this->artisan('device-logs:prune', [
        '--before' => $illegalDate,
        '--dry-run' => true,
    ])->assertFailed();
});

test('API endpoint POST /api/device-logs/prune returns 422 if cutoff date is newer than 1 year before today', function () {
    $response = $this->postJson('/api/device-logs/prune', [
        'before' => now()->subMonths(2)->format('Y-m-d'),
        'dry_run' => true,
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});

