<?php

namespace Tests\Feature;

use App\Models\Biometrics;
use App\Models\DeviceLogs;
use App\Models\DeviceLogsHrbliz;
use App\Models\Devices;
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
                $table->boolean('for_attendance')->default(0);
                $table->boolean('is_hrbliz')->default(0);
                $table->boolean('receiver_by_default')->default(1);
                $table->string('fp_version')->default('v10')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('last_cleared_at')->nullable();
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

    public function test_manual_punch_get_returns_metadata_and_schema(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches*' => \Illuminate\Support\Facades\Http::response([
                'status' => 'online',
                'message' => 'Endpoint is active',
            ], 200),
        ]);

        $response = $this->getJson('/api/manual-punch');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'endpoint' => '/api/manual-punch',
            ])
            ->assertJsonStructure([
                'success',
                'endpoint',
                'zkteco_server',
                'request_schema',
                'punch_status_reference',
            ]);
    }

    public function test_manual_punch_records_in_database_and_synchronizes(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches' => \Illuminate\Support\Facades\Http::response([
                'success' => true,
                'message' => 'Punch recorded',
            ], 200),
        ]);

        Biometrics::create([
            'biometric_id' => 7771,
            'name' => 'Manuel Input Test',
        ]);

        $payload = [
            'biometric_id' => '7771',
            'date_time' => '2026-11-09 08:30:00',
            'status' => 'in',
            'device_name' => 'MANUAL_OFFICE',
            'sync_zkteco' => true,
        ];

        $response = $this->postJson('/api/manual-punch', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'biometric_id' => '7771',
                    'name' => 'Manuel Input Test',
                    'date_time' => '2026-11-09 08:30:00',
                    'status' => 0,
                    'status_label' => 'Check-In',
                    'device_name' => 'MANUAL_OFFICE',
                ],
                'zkteco_sync' => [
                    'success' => true,
                    'pin_sent' => '7771',
                    'status_sent' => 0,
                ],
            ]);

        $this->assertDatabaseHas('device_logs', [
            'biometric_id' => '7771',
            'date_time' => '2026-11-09 08:30:00',
            'status' => 0,
            'device_name' => 'MANUAL_OFFICE',
        ]);
    }

    public function test_manual_punch_with_hrbliz_biometric_id_maps_canonical_id(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
        ]);

        Biometrics::create([
            'biometric_id' => 493,
            'hrbliz_biometric_id' => 5180,
            'name' => 'Dr. HRBLIZ Employee',
        ]);

        $payload = [
            'hrbliz_biometric_id' => '5180',
            'date_time' => '2026-11-09 17:05:00',
            'status' => 'out',
        ];

        $response = $this->postJson('/api/manual-punch', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'biometric_id' => '493',
                    'hrbliz_biometric_id' => '5180',
                    'name' => 'Dr. HRBLIZ Employee',
                    'status' => 1,
                    'status_label' => 'Check-Out',
                ],
                'zkteco_sync' => [
                    'success' => true,
                    'pin_sent' => '5180',
                    'status_sent' => 1,
                ],
            ]);

        $this->assertDatabaseHas('device_logs', [
            'biometric_id' => '493',
            'date_time' => '2026-11-09 17:05:00',
            'status' => 1,
        ]);
    }

    public function test_manual_punch_skips_duplicates_safely(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
        ]);

        $payload = [
            'biometric_id' => '8888',
            'date_time' => '2026-11-09 09:00:00',
            'status' => 0,
        ];

        $first = $this->postJson('/api/manual-punch', $payload);
        $first->assertStatus(201);

        $second = $this->postJson('/api/manual-punch', $payload);
        $second->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_duplicate' => true,
            ]);
    }

    public function test_manual_punch_batch_processes_multiple_records(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'inserted_count' => 2], 200),
        ]);

        $payload = [
            'punches' => [
                [
                    'biometric_id' => '9001',
                    'date_time' => '2026-11-09 08:00:00',
                    'status' => 0,
                ],
                [
                    'biometric_id' => '9002',
                    'date_time' => '2026-11-09 08:05:00',
                    'status' => 0,
                ],
            ],
            'sync_zkteco' => true,
        ];

        $response = $this->postJson('/api/manual-punch', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'inserted_count' => 2,
                'total_received' => 2,
            ]);

        $this->assertDatabaseHas('device_logs', ['biometric_id' => '9001']);
        $this->assertDatabaseHas('device_logs', ['biometric_id' => '9002']);
    }

    public function test_manual_punch_view_loads_devices_from_model(): void
    {
        Devices::create([
            'device_name' => 'Main Gate ZK',
            'serial_number' => 'ZK-SN-001',
            'ip_address' => '192.168.1.201',
            'is_active' => 1,
            'is_hrbliz' => 0,
        ]);

        $response = $this->get('/manual-punch');

        $response->assertStatus(200);
        $response->assertViewHas('devices');
        $response->assertSee('Main Gate ZK');
        $response->assertSee('ZK-SN-001');
    }

    public function test_manual_punch_saves_device_name_in_umis_and_serial_number_to_8080(): void
    {
        $device = Devices::create([
            'device_name' => 'OPD Entrance Biometric',
            'serial_number' => 'OPD-SN-9999',
            'ip_address' => '192.168.1.150',
            'is_active' => 1,
            'is_hrbliz' => 0,
        ]);

        $forwardedPayload = null;
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches' => function (\Illuminate\Http\Client\Request $req) use (&$forwardedPayload) {
                $forwardedPayload = $req->data();
                return \Illuminate\Support\Facades\Http::response(['success' => true], 200);
            },
        ]);

        $payload = [
            'biometric_id' => '1234',
            'date_time' => '2026-11-10 08:00:00',
            'status' => 0,
            'device_id' => $device->id,
            'sync_zkteco' => true,
        ];

        $response = $this->postJson('/api/manual-punch', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'biometric_id' => '1234',
                    'device_name' => 'OPD Entrance Biometric',
                    'serial_number' => 'OPD-SN-9999',
                ],
            ]);

        // 1. In UMIS device logs: save device name
        $this->assertDatabaseHas('device_logs', [
            'biometric_id' => '1234',
            'date_time' => '2026-11-10 08:00:00',
            'device_name' => 'OPD Entrance Biometric',
        ]);

        // 2. In 8080 server for hrbliz: save serial number
        $this->assertNotNull($forwardedPayload);
        $this->assertEquals('OPD-SN-9999', $forwardedPayload['device_sn']);
    }

    public function test_manual_punch_with_hrbliz_device_saves_name_in_umis_and_serial_to_8080(): void
    {
        $hrblizDevice = Devices::create([
            'device_name' => 'HRBLIZ Special Terminal',
            'serial_number' => 'HRBLIZ-SN-777',
            'ip_address' => '192.168.1.180',
            'is_active' => 1,
            'is_hrbliz' => 1,
        ]);

        $forwardedPayload = null;
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches' => function (\Illuminate\Http\Client\Request $req) use (&$forwardedPayload) {
                $forwardedPayload = $req->data();
                return \Illuminate\Support\Facades\Http::response(['success' => true], 200);
            },
        ]);

        $payload = [
            'biometric_id' => '5678',
            'date_time' => '2026-11-10 17:00:00',
            'status' => 1,
            'device_id' => $hrblizDevice->id,
            'sync_zkteco' => true,
        ];

        $response = $this->postJson('/api/manual-punch', $payload);

        $response->assertStatus(201);

        // 1. In UMIS device logs: save device name into device_logs_hrbliz
        $this->assertDatabaseHas('device_logs_hrbliz', [
            'biometric_id' => '5678',
            'date_time' => '2026-11-10 17:00:00',
            'device_name' => 'HRBLIZ Special Terminal',
        ]);

        // 2. In 8080 server for hrbliz: save serial number
        $this->assertNotNull($forwardedPayload);
        $this->assertEquals('HRBLIZ-SN-777', $forwardedPayload['device_sn']);
    }

    public function test_manual_punch_batch_saves_device_name_in_umis_and_serial_to_8080(): void
    {
        $device = Devices::create([
            'device_name' => 'Batch Test Terminal',
            'serial_number' => 'BATCH-SN-888',
            'ip_address' => '192.168.1.199',
            'is_active' => 1,
            'is_hrbliz' => 0,
        ]);

        $forwardedBatch = null;
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches' => function (\Illuminate\Http\Client\Request $req) use (&$forwardedBatch) {
                $forwardedBatch = $req->data();
                return \Illuminate\Support\Facades\Http::response([
                    'success' => true,
                    'inserted_count' => count($req->data()),
                ], 200);
            },
        ]);

        $payload = [
            'punches' => [
                [
                    'biometric_id' => '4441',
                    'date_time' => '2026-11-10 08:00:00',
                    'status' => 0,
                    'device_id' => $device->id,
                ],
                [
                    'biometric_id' => '4442',
                    'date_time' => '2026-11-10 08:05:00',
                    'status' => 0,
                    'device_id' => $device->id,
                ],
            ],
            'sync_zkteco' => true,
        ];

        $response = $this->postJson('/api/manual-punch', $payload);

        $response->assertStatus(201);

        // 1. In UMIS device logs: save device name
        $this->assertDatabaseHas('device_logs', [
            'biometric_id' => '4441',
            'device_name' => 'Batch Test Terminal',
        ]);
        $this->assertDatabaseHas('device_logs', [
            'biometric_id' => '4442',
            'device_name' => 'Batch Test Terminal',
        ]);

        // 2. In 8080 server: save serial number
        $this->assertNotNull($forwardedBatch);
        $this->assertEquals('BATCH-SN-888', $forwardedBatch[0]['device_sn']);
        $this->assertEquals('BATCH-SN-888', $forwardedBatch[1]['device_sn']);
    }

    public function test_manual_punch_formats_name_and_sets_created_updated_at_to_entered_time(): void
    {
        $forwardedPayload = null;
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches' => function (\Illuminate\Http\Client\Request $req) use (&$forwardedPayload) {
                $forwardedPayload = $req->data();
                return \Illuminate\Support\Facades\Http::response(['success' => true], 200);
            },
        ]);

        // Biometric record with "Lastname, Firstname" format
        Biometrics::create([
            'biometric_id' => 9988,
            'name' => 'Caimor, Reenjay',
        ]);

        $customEnteredTime = '2026-11-09 10:44:47';

        $payload = [
            'biometric_id' => '9988',
            'date_time' => $customEnteredTime,
            'status' => 0,
            'device_name' => 'TAD_ENTRANCE',
            'sync_zkteco' => true,
        ];

        $response = $this->postJson('/api/manual-punch', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'biometric_id' => '9988',
                    'name' => 'Reenjay Caimor',
                    'date_time' => $customEnteredTime,
                    'created_at' => $customEnteredTime,
                    'updated_at' => $customEnteredTime,
                ],
            ]);

        // Verify database has formatted name and exact matching timestamps
        $log = \App\Models\DeviceLogs::where('biometric_id', '9988')
            ->where('date_time', $customEnteredTime)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Reenjay Caimor', $log->name);
        $this->assertEquals($customEnteredTime, \Carbon\Carbon::parse($log->created_at)->format('Y-m-d H:i:s'));
        $this->assertEquals($customEnteredTime, \Carbon\Carbon::parse($log->updated_at)->format('Y-m-d H:i:s'));

        // Verify 8080 forwarded payload includes created_at and updated_at
        $this->assertNotNull($forwardedPayload);
        $this->assertEquals('9988', $forwardedPayload['pin']);
        $this->assertEquals($customEnteredTime, $forwardedPayload['time']);
        $this->assertEquals($customEnteredTime, $forwardedPayload['created_at']);
        $this->assertEquals($customEnteredTime, $forwardedPayload['updated_at']);
    }

    public function test_manual_punch_with_employee_profile_resolves_full_umis_name(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
        ]);

        $mockLogsRepo = \Mockery::mock(\App\Repositories\LogsRepository::class);
        $mockLogsRepo->shouldReceive('getEmployeeNameAndStatus')
            ->with(493)
            ->andReturn(['name' => 'Reenjay M. Caimor ', 'is_external' => false]);
        $mockLogsRepo->shouldReceive('logExists')->andReturn(false);
        $this->app->instance(\App\Repositories\LogsRepository::class, $mockLogsRepo);

        $testTime = '2026-11-08 07:45:00';

        $payload = [
            'biometric_id' => '493',
            'date_time' => $testTime,
            'status' => 0,
            'sync_zkteco' => true,
        ];

        $response = $this->postJson('/api/manual-punch', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'biometric_id' => '493',
                    'name' => 'Reenjay M. Caimor',
                    'date_time' => $testTime,
                    'created_at' => $testTime,
                    'updated_at' => $testTime,
                ],
            ]);

        $log = \App\Models\DeviceLogs::where('biometric_id', '493')
            ->where('date_time', $testTime)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Reenjay M. Caimor', $log->name);
        $this->assertEquals($testTime, \Carbon\Carbon::parse($log->created_at)->format('Y-m-d H:i:s'));
        $this->assertEquals($testTime, \Carbon\Carbon::parse($log->updated_at)->format('Y-m-d H:i:s'));
    }

    public function test_manual_punch_batch_sets_created_and_updated_at_matching_entered_time(): void
    {
        $forwardedBatch = null;
        \Illuminate\Support\Facades\Http::fake([
            '*/api/punches' => function (\Illuminate\Http\Client\Request $req) use (&$forwardedBatch) {
                $forwardedBatch = $req->data();
                return \Illuminate\Support\Facades\Http::response([
                    'success' => true,
                    'inserted_count' => count($req->data()),
                ], 200);
            },
        ]);

        $entryTime1 = '2026-11-05 08:00:00';
        $entryTime2 = '2026-11-05 17:00:00';

        $payload = [
            'punches' => [
                [
                    'biometric_id' => '3311',
                    'date_time' => $entryTime1,
                    'status' => 0,
                    'name' => 'Caimor, Reenjay M.',
                ],
                [
                    'biometric_id' => '3311',
                    'date_time' => $entryTime2,
                    'status' => 1,
                    'name' => 'Caimor, Reenjay M.',
                ],
            ],
            'sync_zkteco' => true,
        ];

        $response = $this->postJson('/api/manual-punch', $payload);

        $response->assertStatus(201);

        $log1 = \App\Models\DeviceLogs::where('biometric_id', '3311')
            ->where('date_time', $entryTime1)
            ->first();
        $this->assertNotNull($log1);
        $this->assertEquals('Reenjay M. Caimor', $log1->name);
        $this->assertEquals($entryTime1, \Carbon\Carbon::parse($log1->created_at)->format('Y-m-d H:i:s'));
        $this->assertEquals($entryTime1, \Carbon\Carbon::parse($log1->updated_at)->format('Y-m-d H:i:s'));

        $log2 = \App\Models\DeviceLogs::where('biometric_id', '3311')
            ->where('date_time', $entryTime2)
            ->first();
        $this->assertNotNull($log2);
        $this->assertEquals('Reenjay M. Caimor', $log2->name);
        $this->assertEquals($entryTime2, \Carbon\Carbon::parse($log2->created_at)->format('Y-m-d H:i:s'));
        $this->assertEquals($entryTime2, \Carbon\Carbon::parse($log2->updated_at)->format('Y-m-d H:i:s'));

        // Check 8080 batch forwarded payloads
        $this->assertNotNull($forwardedBatch);
        $this->assertEquals($entryTime1, $forwardedBatch[0]['created_at']);
        $this->assertEquals($entryTime1, $forwardedBatch[0]['updated_at']);
        $this->assertEquals($entryTime2, $forwardedBatch[1]['created_at']);
        $this->assertEquals($entryTime2, $forwardedBatch[1]['updated_at']);
    }

    public function test_search_employees_returns_formatted_name(): void
    {
        Biometrics::create([
            'biometric_id' => 9912,
            'name' => 'Dela Cruz, Juan',
        ]);

        $response = $this->getJson('/punches/employees?q=9912');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    [
                        'biometric_id' => '9912',
                        'name' => 'Juan Dela Cruz',
                    ],
                ],
            ]);
    }
}
