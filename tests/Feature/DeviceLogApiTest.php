<?php

namespace Tests\Feature;

use App\Models\Biometrics;
use App\Models\DeviceLogs;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeviceLogApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('device_logs')) {
            Schema::create('device_logs', function (Blueprint $table) {
                $table->id();
                $table->string('biometric_id', 191);
                $table->string('name', 191)->nullable();
                $table->date('dtr_date');
                $table->dateTime('date_time');
                $table->integer('status');
                $table->integer('is_Shifting')->default(0);
                $table->text('schedule')->nullable();
                $table->integer('active')->default(1);
                $table->string('device_name', 200)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('device_logs_hrbliz')) {
            Schema::create('device_logs_hrbliz', function (Blueprint $table) {
                $table->id();
                $table->string('biometric_id', 191);
                $table->string('name', 191)->nullable();
                $table->date('dtr_date');
                $table->dateTime('date_time');
                $table->integer('status');
                $table->integer('is_Shifting')->default(0);
                $table->text('schedule')->nullable();
                $table->integer('active')->default(1);
                $table->string('device_name', 200)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('biometrics')) {
            Schema::create('biometrics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('biometric_id')->unique();
                $table->unsignedBigInteger('hrbliz_biometric_id')->nullable();
                $table->string('name')->nullable();
                $table->integer('privilege')->default(0);
                $table->longText('biometric')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_can_insert_using_hrbliz_biometric_id_and_resolves_canonical_id(): void
    {
        Biometrics::create([
            'biometric_id' => 5005,
            'hrbliz_biometric_id' => 8765,
            'name' => 'Basilio Santos',
        ]);

        $payload = [
            'hrbliz_biometric_id' => '8765',
            'date_time'           => '2026-10-23 08:00:00',
            'status'              => 0,
            'device_name'         => 'HRBLIZ_TERMINAL',
        ];

        $response = $this->postJson('/api/device-logs', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'biometric_id' => '5005',
                    'hrbliz_biometric_id' => '8765',
                    'name' => 'Basilio Santos',
                    'dtr_date' => '2026-10-23',
                    'date_time' => '2026-10-23 08:00:00',
                    'status' => 0,
                    'device_name' => 'HRBLIZ_TERMINAL',
                ],
            ]);

        $this->assertDatabaseHas('device_logs', [
            'biometric_id' => '5005',
            'date_time' => '2026-10-23 08:00:00',
            'name' => 'Basilio Santos',
        ]);
    }

    public function test_can_query_logs_using_hrbliz_biometric_id(): void
    {
        Biometrics::create([
            'biometric_id' => 6006,
            'hrbliz_biometric_id' => 9876,
            'name' => 'Crisostomo Ibarra',
        ]);

        DeviceLogs::create([
            'biometric_id' => '6006',
            'name'         => 'Crisostomo Ibarra',
            'dtr_date'     => '2026-10-23',
            'date_time'    => '2026-10-23 07:55:00',
            'status'       => 0,
            'is_Shifting'  => 0,
            'active'       => 1,
            'device_name'  => 'HRBLIZ_GATE',
        ]);

        $response = $this->getJson('/api/device-logs?hrbliz_biometric_id=9876');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('6006', $response->json('data.0.biometric_id'));
    }

    public function test_can_insert_single_device_log_with_default_biometric_id(): void
    {
        $payload = [
            'biometric_id' => '999991',
            'name'         => 'Test Employee Single',
            'date_time'    => '2026-10-23 08:00:00',
            'status'       => 0,
            'device_name'  => 'API_TEST_DEVICE',
        ];

        $response = $this->postJson('/api/device-logs', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'biometric_id' => '999991',
                    'name' => 'Test Employee Single',
                    'dtr_date' => '2026-10-23',
                    'date_time' => '2026-10-23 08:00:00',
                    'status' => 0,
                    'device_name' => 'API_TEST_DEVICE',
                ],
            ]);

        $this->assertDatabaseHas('device_logs', [
            'biometric_id' => '999991',
            'date_time' => '2026-10-23 08:00:00',
        ]);
    }

    public function test_can_insert_batch_device_logs(): void
    {
        $payload = [
            'logs' => [
                [
                    'hrbliz_biometric_id' => '999992',
                    'name'                => 'Batch User 1',
                    'date_time'           => '2026-10-23 08:05:00',
                    'status'              => 0,
                    'device_name'         => 'BATCH_DEVICE',
                ],
                [
                    'biometric_id'        => '999993',
                    'name'                => 'Batch User 2',
                    'date_time'           => '2026-10-23 12:00:00',
                    'status'              => 1,
                    'device_name'         => 'BATCH_DEVICE',
                ],
            ],
        ];

        $response = $this->postJson('/api/device-logs', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'total_received' => 2,
                'inserted_count' => 2,
                'skipped_count' => 0,
            ]);

        $this->assertDatabaseHas('device_logs', ['biometric_id' => '999992']);
        $this->assertDatabaseHas('device_logs', ['biometric_id' => '999993']);
    }

    public function test_auto_resolves_employee_name_from_biometrics_if_missing(): void
    {
        Biometrics::create([
            'biometric_id' => 8888,
            'name' => 'Maria Clara',
        ]);

        $payload = [
            'biometric_id' => '8888',
            'date_time'    => '2026-10-23 08:30:00',
            'status'       => 0,
        ];

        $response = $this->postJson('/api/device-logs', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'biometric_id' => '8888',
                    'name' => 'Maria Clara',
                ],
            ]);

        $this->assertDatabaseHas('device_logs', [
            'biometric_id' => '8888',
            'name' => 'Maria Clara',
        ]);
    }

    public function test_prevents_duplicate_logs_by_default(): void
    {
        $payload = [
            'hrbliz_biometric_id' => '999994',
            'name'                => 'Duplicate Test',
            'date_time'           => '2026-10-23 08:15:00',
            'status'              => 0,
        ];

        // First insert
        $first = $this->postJson('/api/device-logs', $payload);
        $first->assertStatus(201);

        // Second identical insert
        $second = $this->postJson('/api/device-logs', $payload);
        $second->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_duplicate' => true,
            ]);
    }

    public function test_validation_fails_when_both_biometric_ids_are_missing(): void
    {
        $payload = [
            'date_time' => '2026-10-23 08:00:00',
        ];

        $response = $this->postJson('/api/device-logs', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }
}
