<?php

namespace Tests\Feature;

use App\Models\Biometrics;
use App\Models\DeviceLogs;
use App\Models\Devices;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSchedule;
use App\Models\Schedule;
use App\Models\TimeShifts;
use App\Services\UmisPunchClassifierService;
use App\Services\ZkPunchForwarderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ZkPunchForwardingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTablesExist();

        config([
            'zkteco.forward_enabled' => true,
            'zkteco.host' => 'http://192.168.9.243:8080',
            'zkteco.timeout' => 3,
            'zkteco.device_sn' => 'RKQ4242300018',
            'zkteco.batch_size' => 50,
            'zkteco.device_ids' => '27',
            'zkteco.use_hrbliz_id' => true,
            'zkteco.strict_hrbliz_status' => true,
        ]);
    }

    protected function ensureTablesExist(): void
    {
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

        if (!Schema::hasTable('employee_profiles')) {
            Schema::create('employee_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('biometric_id')->nullable();
                $table->unsignedBigInteger('personal_information_id')->nullable();
                $table->timestamp('deactivated_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('time_shifts')) {
            Schema::create('time_shifts', function (Blueprint $table) {
                $table->id();
                $table->string('first_in')->nullable();
                $table->string('first_out')->nullable();
                $table->string('second_in')->nullable();
                $table->string('second_out')->nullable();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('schedules')) {
            Schema::create('schedules', function (Blueprint $table) {
                $table->id();
                $table->date('date');
                $table->boolean('is_weekend')->default(0);
                $table->string('status')->nullable();
                $table->string('remarks')->nullable();
                $table->unsignedBigInteger('time_shift_id')->nullable();
                $table->unsignedBigInteger('holiday_id')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('employee_profile_schedule')) {
            Schema::create('employee_profile_schedule', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_profile_id');
                $table->unsignedBigInteger('schedule_id');
                $table->timestamps();
            });
        }
    }

    protected function createDeviceWithId(int $id, string $name, string $sn, string $ip): Devices
    {
        DB::table('devices')->insert([
            'id' => $id,
            'device_name' => $name,
            'serial_number' => $sn,
            'ip_address' => $ip,
            'is_active' => 1,
            'is_registration' => 0,
            'for_attendance' => 0,
            'is_hrbliz' => 0,
            'receiver_by_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Devices::findOrFail($id);
    }

    protected function assignUmisSchedule(int $biometricId, string $date, ?string $firstIn, ?string $firstOut, ?string $secondIn = null, ?string $secondOut = null): void
    {
        $profile = EmployeeProfile::where('biometric_id', $biometricId)->first();
        if (!$profile) {
            $profileId = DB::table('employee_profiles')->insertGetId([
                'biometric_id' => $biometricId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $profileId = $profile->id;
        }

        $shift = TimeShifts::create([
            'first_in' => $firstIn,
            'first_out' => $firstOut,
            'second_in' => $secondIn,
            'second_out' => $secondOut,
        ]);

        $schedule = Schedule::create([
            'date' => $date,
            'time_shift_id' => $shift->id,
        ]);

        EmployeeSchedule::create([
            'employee_profile_id' => $profileId,
            'schedule_id' => $schedule->id,
        ]);
    }

    public function test_device_27_forwards_using_umis_schedule_on_canonical_id_and_maps_to_hrbliz_id(): void
    {
        $device27 = $this->createDeviceWithId(27, 'UMIS Main Gate', 'RKQ4242300018', '192.168.10.27');

        Biometrics::create([
            'biometric_id' => 493,
            'hrbliz_biometric_id' => 5180,
            'name' => 'Caimor, Reenjay',
        ]);

        // Custom UMIS shift 07:00-12:00 / 13:00-16:00 assigned to canonical biometric_id 493
        $this->assignUmisSchedule(493, '2026-11-26', '07:00:00', '12:00:00', '13:00:00', '16:00:00');

        $sentPayloads = [];
        Http::fake([
            'http://192.168.9.243:8080/api/punches' => function (Request $req) use (&$sentPayloads) {
                $sentPayloads[] = $req->data();
                return Http::response(['success' => true, 'inserted_count' => count($req->data())], 200);
            },
        ]);

        $forwarder = new ZkPunchForwarderService(new UmisPunchClassifierService());

        // Raw status from UMIS device is 0 for ALL punches (typical biometric hardware behavior)
        $result = $forwarder->forwardBatch([
            ['canonical_pin' => 493, 'time' => '2026-11-26 07:04:00', 'status' => 0, 'device' => $device27],
            ['canonical_pin' => 493, 'time' => '2026-11-26 12:02:00', 'status' => 0, 'device' => $device27],
            ['canonical_pin' => 493, 'time' => '2026-11-26 12:58:00', 'status' => 0, 'device' => $device27],
            ['canonical_pin' => 493, 'time' => '2026-11-26 16:05:00', 'status' => 0, 'device' => $device27],
        ], $device27);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $sentPayloads);

        $batch = $sentPayloads[0];
        $this->assertCount(4, $batch);

        // Must send hrbliz_biometric_id (5180) and device serial_number (RKQ4242300018)
        // Must classify 0, 1, 0, 1 from UMIS schedule despite all raw statuses being 0
        $this->assertEquals('5180', $batch[0]['pin']);
        $this->assertEquals(0, $batch[0]['status']);
        $this->assertEquals('RKQ4242300018', $batch[0]['device_sn']);

        $this->assertEquals('5180', $batch[1]['pin']);
        $this->assertEquals(1, $batch[1]['status']);

        $this->assertEquals('5180', $batch[2]['pin']);
        $this->assertEquals(0, $batch[2]['status']);

        $this->assertEquals('5180', $batch[3]['pin']);
        $this->assertEquals(1, $batch[3]['status']);
    }

    public function test_non_27_device_and_null_device_are_strictly_blocked_from_forwarding(): void
    {
        $device10 = $this->createDeviceWithId(10, 'Other Terminal', 'OTHER-SN-10', '192.168.10.10');

        Http::fake();

        $forwarder = new ZkPunchForwarderService(new UmisPunchClassifierService());

        $singleRes = $forwarder->forwardPunch(493, '2026-11-26 08:00:00', 0, $device10);
        $this->assertFalse($singleRes);

        $batchOther = $forwarder->forwardBatch([
            ['canonical_pin' => 493, 'time' => '2026-11-26 08:00:00', 'status' => 0, 'device' => $device10],
        ], $device10);
        $this->assertEquals(0, $batchOther['total']);

        // Null device must ALSO be rejected when ZK_FORWARD_DEVICE_IDS=27 is configured
        $batchNull = $forwarder->forwardBatch([
            ['canonical_pin' => 493, 'time' => '2026-11-26 08:00:00', 'status' => 0],
        ]);
        $this->assertEquals(0, $batchNull['total']);

        Http::assertNothingSent();
    }

    public function test_no_schedule_on_punch_date_falls_back_to_default_8_12_1_5_exclusively_for_device_27_and_ignores_stale_dates(): void
    {
        $device27 = $this->createDeviceWithId(27, 'UMIS Main Gate', 'RKQ4242300018', '192.168.10.27');

        Biometrics::create([
            'biometric_id' => 610,
            'hrbliz_biometric_id' => 6100,
            'name' => 'No Schedule Today User',
        ]);

        // Employee had a night shift 5 days ago, but has NO schedule on 2026-11-26
        $this->assignUmisSchedule(610, '2026-11-21', '22:00:00', '06:00:00');

        $classifier = new UmisPunchClassifierService();
        $forwarder = new ZkPunchForwarderService($classifier);

        $prepared = $forwarder->prepareBatch([
            ['canonical_pin' => 610, 'time' => '2026-11-26 07:55:00', 'status' => 0, 'device' => $device27],
            ['canonical_pin' => 610, 'time' => '2026-11-26 12:05:00', 'status' => 0, 'device' => $device27],
            ['canonical_pin' => 610, 'time' => '2026-11-26 12:55:00', 'status' => 0, 'device' => $device27],
            ['canonical_pin' => 610, 'time' => '2026-11-26 17:05:00', 'status' => 0, 'device' => $device27],
        ], $device27);

        $preview = $prepared['preview'];
        $this->assertCount(4, $preview);

        $this->assertEquals('default_8_12_1_5', $preview[0]['classification_source']);
        $this->assertEquals([0, 1, 0, 1], array_column($preview, 'status'));
        $this->assertEquals('6100', $preview[0]['pin']);

        // When default schedule fallback is disabled (non-27 callers), 8-12/1-5 is NOT used
        $non27Details = $classifier->classifyWithDetails(610, '2026-11-26 17:05:00', [], allowDefaultScheduleFallback: false);
        $this->assertEquals('chronological_fallback', $non27Details['source']);
    }

    public function test_classifier_handles_bounce_taps_skipped_lunch_early_out_sparse_2_slot_and_cross_midnight(): void
    {
        $classifier = new UmisPunchClassifierService();

        // 1. Rapid bounce tap (< 60s) must inherit 0 and NOT invert lunch out
        $this->assignUmisSchedule(701, '2026-11-26', '08:00:00', '12:00:00', '13:00:00', '17:00:00');
        $b1 = $classifier->classifyStatus(701, '2026-11-26 07:50:10', ['2026-11-26 07:50:10']);
        $b2 = $classifier->classifyStatus(701, '2026-11-26 07:50:25', ['2026-11-26 07:50:10', '2026-11-26 07:50:25']);
        $b3 = $classifier->classifyStatus(701, '2026-11-26 12:05:00', ['2026-11-26 07:50:10', '2026-11-26 07:50:25', '2026-11-26 12:05:00']);
        $this->assertEquals([0, 0, 1], [$b1, $b2, $b3]);

        // 2. Skipped lunch + early afternoon out at 16:00 must classify as 1 (second_out), NOT 0 (second_in)
        $earlyOut = $classifier->classifyWithDetails(701, '2026-11-26 16:00:00', ['2026-11-26 08:00:00', '2026-11-26 16:00:00']);
        $this->assertEquals(1, $earlyOut['status']);
        $this->assertEquals('second_out', $earlyOut['slot']);

        // 3. Sparse 2-slot PM schedule (first_in=14:00, first_out=null, second_in=null, second_out=22:00)
        $this->assignUmisSchedule(702, '2026-11-26', '14:00:00', null, null, '22:00:00');
        $pmIn = $classifier->classifyWithDetails(702, '2026-11-26 13:55:00', ['2026-11-26 13:55:00']);
        $pmOut = $classifier->classifyWithDetails(702, '2026-11-26 22:05:00', ['2026-11-26 13:55:00', '2026-11-26 22:05:00']);
        $this->assertEquals(0, $pmIn['status']);
        $this->assertEquals(1, $pmOut['status']);
        $this->assertEquals('umis_schedule', $pmOut['source']);

        // 4. Cross-midnight shift (22:00 -> 06:00 next day, with no schedule on next day)
        $this->assignUmisSchedule(703, '2026-11-25', '22:00:00', '06:00:00');
        $nightIn = $classifier->classifyWithDetails(703, '2026-11-25 21:55:00', ['2026-11-25 21:55:00']);
        $nightOutNextMorning = $classifier->classifyWithDetails(703, '2026-11-26 06:05:00', ['2026-11-26 06:05:00']);
        $this->assertEquals(0, $nightIn['status']);
        $this->assertEquals(1, $nightOutNextMorning['status']);
        $this->assertEquals('prev_cross_midnight', $nightOutNextMorning['source']);

        // 5. Late lunch break (07:04, 13:02, 13:04, 16:00 on 07:00-12:00 / 13:00-16:00)
        $this->assignUmisSchedule(704, '2026-11-26', '07:00:00', '12:00:00', '13:00:00', '16:00:00');
        $all4 = ['2026-11-26 07:04:00', '2026-11-26 13:02:00', '2026-11-26 13:04:00', '2026-11-26 16:00:00'];
        $this->assertEquals(0, $classifier->classifyStatus(704, '2026-11-26 07:04:00', $all4));
        $this->assertEquals(1, $classifier->classifyStatus(704, '2026-11-26 13:02:00', $all4));
        $this->assertEquals(0, $classifier->classifyStatus(704, '2026-11-26 13:04:00', $all4));
        $this->assertEquals(1, $classifier->classifyStatus(704, '2026-11-26 16:00:00', $all4));
    }

    public function test_iclock_cdata_multiline_push_batches_into_single_http_post_for_device_27(): void
    {
        $this->createDeviceWithId(27, 'UMIS Main Gate', 'RKQ4242300018', '192.168.10.27');

        // Isolate test from disk-based storage/logs/device_logs.log duplicate scanning across repeated runs
        $logsRepo = \Mockery::mock(\App\Repositories\LogsRepository::class, [app(\App\Contracts\DeviceRepositoryInterface::class)])->makePartial();
        $logsRepo->shouldReceive('logExists')->andReturn(false);
        $this->app->instance(\App\Contracts\LogsRepositoryInterface::class, $logsRepo);

        Biometrics::create([
            'biometric_id' => 801,
            'hrbliz_biometric_id' => 8801,
            'name' => 'Dela Cruz, Juan',
        ]);

        // No schedule on 2026-11-26 -> should use default 8-12 / 1-5pm exclusively for device 27
        $httpRequests = [];
        Http::fake([
            'http://192.168.9.243:8080/api/punches' => function (Request $req) use (&$httpRequests) {
                $httpRequests[] = $req->data();
                return Http::response(['success' => true, 'inserted_count' => count($req->data())], 200);
            },
        ]);

        $rawBody = implode("\n", [
            "801\t2026-11-26 07:58:00\t0",
            "801\t2026-11-26 12:03:00\t0",
            "801\t2026-11-26 12:59:00\t0",
            "801\t2026-11-26 17:04:00\t0",
        ]);

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=RKQ4242300018&table=ATTLOG',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '192.168.10.27', 'CONTENT_TYPE' => 'text/plain'],
            $rawBody
        );

        $response->assertStatus(200);

        // Saved into UMIS device_logs under canonical biometric_id 801
        $this->assertEquals(4, DeviceLogs::where('biometric_id', '801')->where('dtr_date', '2026-11-26')->count());

        // Forwarded in ONE batched HTTP request (not 4) with hrbliz_biometric_id 8801 and statuses 0, 1, 0, 1
        $this->assertCount(1, $httpRequests);
        $this->assertCount(4, $httpRequests[0]);
        $this->assertEquals(['8801', '8801', '8801', '8801'], array_column($httpRequests[0], 'pin'));
        $this->assertEquals([0, 1, 0, 1], array_column($httpRequests[0], 'status'));
        $this->assertEquals('RKQ4242300018', $httpRequests[0][0]['device_sn']);
    }

    public function test_zk_sync_punches_filters_device_27_in_sql_and_never_double_maps_pin(): void
    {
        $this->createDeviceWithId(27, 'UMIS Main Gate', 'RKQ4242300018', '192.168.10.27');
        $this->createDeviceWithId(10, 'Other Gate', 'OTHER-SN-10', '192.168.10.10');

        // User A: canonical 493 -> hrbliz 5180
        Biometrics::create([
            'biometric_id' => 493,
            'hrbliz_biometric_id' => 5180,
            'name' => 'User A',
        ]);
        // User B: canonical 5180 -> hrbliz 9999 (proves double-mapping would have corrupted 5180 -> 9999!)
        Biometrics::create([
            'biometric_id' => 5180,
            'hrbliz_biometric_id' => 9999,
            'name' => 'User B Collision Guard',
        ]);

        $this->assignUmisSchedule(493, '2026-11-26', '08:00:00', '12:00:00', '13:00:00', '17:00:00');

        // Insert earlier logs from non-27 device to ensure --limit=2 still finds device 27's logs
        DeviceLogs::create([
            'biometric_id' => '5180',
            'name' => 'User B',
            'dtr_date' => '2026-11-26',
            'date_time' => '2026-11-26 06:00:00',
            'status' => 0,
            'device_name' => 'Other Gate',
        ]);
        DeviceLogs::create([
            'biometric_id' => '5180',
            'name' => 'User B',
            'dtr_date' => '2026-11-26',
            'date_time' => '2026-11-26 06:05:00',
            'status' => 0,
            'device_name' => 'Other Gate',
        ]);

        // Device 27 logs for canonical 493
        DeviceLogs::create([
            'biometric_id' => '493',
            'name' => 'User A',
            'dtr_date' => '2026-11-26',
            'date_time' => '2026-11-26 08:00:00',
            'status' => 0,
            'device_name' => 'UMIS Main Gate',
        ]);
        DeviceLogs::create([
            'biometric_id' => '493',
            'name' => 'User A',
            'dtr_date' => '2026-11-26',
            'date_time' => '2026-11-26 17:00:00',
            'status' => 0,
            'device_name' => 'UMIS Main Gate',
        ]);

        $postedBatches = [];
        Http::fake([
            'http://192.168.9.243:8080/api/punches' => function (Request $req) use (&$postedBatches) {
                if ($req->method() === 'GET') {
                    return Http::response(['status' => 'online'], 200);
                }
                $postedBatches[] = $req->data();
                return Http::response(['success' => true, 'inserted_count' => count($req->data()), 'skipped_count' => 0], 200);
            },
        ]);

        $this->artisan('zk:sync-punches', [
            '--date' => '2026-11-26',
            '--limit' => 2,
        ])->assertExitCode(0);

        $this->assertCount(1, $postedBatches);
        $batch = $postedBatches[0];
        $this->assertCount(2, $batch);

        // Must be 5180 (NOT 9999!), statuses [0, 1], device_sn RKQ4242300018 (NOT "UMIS Main Gate")
        $this->assertEquals(['5180', '5180'], array_column($batch, 'pin'));
        $this->assertEquals([0, 1], array_column($batch, 'status'));
        $this->assertEquals(['RKQ4242300018', 'RKQ4242300018'], array_column($batch, 'device_sn'));
    }

    public function test_batch_forward_fails_fast_on_connection_error(): void
    {
        $device27 = $this->createDeviceWithId(27, 'UMIS Main Gate', 'RKQ4242300018', '192.168.10.27');
        config(['zkteco.batch_size' => 2]);

        $attempts = 0;
        Http::fake([
            'http://192.168.9.243:8080/api/punches' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $forwarder = new ZkPunchForwarderService(new UmisPunchClassifierService());
        $punches = [];
        for ($i = 0; $i < 6; $i++) {
            $punches[] = [
                'canonical_pin' => 900 + $i,
                'time' => sprintf('2026-11-26 08:0%d:00', $i),
                'status' => 0,
                'device' => $device27,
            ];
        }

        $result = $forwarder->forwardBatch($punches, $device27);

        $this->assertFalse($result['success']);
        // 6 punches / batch size 2 = 3 chunks, but must abort after chunk 1 connection failure
        $this->assertEquals(1, $attempts);
    }
}
