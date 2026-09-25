<?php

use App\Services\DeviceCommandService;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->testDir = storage_path('framework/testing/device_queues');
    $this->service = new DeviceCommandService($this->testDir);
    $this->service->clearCommands();
});

afterEach(function () {
    if (isset($this->service)) {
        $this->service->clearCommands();
    }
});

test('queues commands and increments id sequentially in json file', function () {
    $cmd1 = $this->service->queueCommand('DEV_001', 'DATA USER PIN=1001');
    expect($cmd1['id'])->toBe(1);
    expect($cmd1['device_sn'])->toBe('DEV_001');
    expect($cmd1['status'])->toBe('PENDING');

    $cmd2 = $this->service->queueCommand('DEV_001', 'DATA UPDATE fingertmp PIN=1001');
    expect($cmd2['id'])->toBe(2);

    $cmd3 = $this->service->queueCommand('DEV_002', 'DATA USER PIN=1002');
    expect($cmd3['id'])->toBe(3);

    $all = $this->service->getAllCommands();
    expect($all)->toHaveCount(3);
});

test('getPendingCommands filters by device_sn and status PENDING with limit', function () {
    $this->service->queueCommand('DEV_001', 'CMD 1');
    $this->service->queueCommand('DEV_001', 'CMD 2');
    $this->service->queueCommand('DEV_001', 'CMD 3');
    $this->service->queueCommand('DEV_002', 'CMD 4');

    $dev1Pending = $this->service->getPendingCommands('DEV_001', 2);
    expect($dev1Pending)->toHaveCount(2);
    expect($dev1Pending[0]['command'])->toBe('CMD 1');
    expect($dev1Pending[1]['command'])->toBe('CMD 2');

    $dev2Pending = $this->service->getPendingCommands('DEV_002', 10);
    expect($dev2Pending)->toHaveCount(1);
    expect($dev2Pending[0]['command'])->toBe('CMD 4');
});

test('markCommandsAsSent updates status to SENT', function () {
    $cmd1 = $this->service->queueCommand('DEV_001', 'CMD 1');
    $cmd2 = $this->service->queueCommand('DEV_001', 'CMD 2');

    $this->service->markCommandsAsSent([$cmd1['id']]);

    $all = $this->service->getAllCommands('DEV_001');
    expect($all[0]['status'])->toBe('SENT');
    expect($all[1]['status'])->toBe('PENDING');

    // Should no longer appear in getPendingCommands
    $pending = $this->service->getPendingCommands('DEV_001');
    expect($pending)->toHaveCount(1);
    expect($pending[0]['id'])->toBe($cmd2['id']);
});

test('recordCommandAck updates status to SUCCESS or FAILED', function () {
    $cmd1 = $this->service->queueCommand('DEV_001', 'CMD 1');
    $cmd2 = $this->service->queueCommand('DEV_001', 'CMD 2');

    // ACK success
    $updated1 = $this->service->recordCommandAck($cmd1['id'], 0);
    expect($updated1)->toBeTrue();

    // ACK failure
    $updated2 = $this->service->recordCommandAck($cmd2['id'], -1);
    expect($updated2)->toBeTrue();

    $all = $this->service->getAllCommands('DEV_001');
    expect($all[0]['status'])->toBe('SUCCESS');
    expect($all[0]['return_code'])->toBe(0);
    expect($all[1]['status'])->toBe('FAILED');
    expect($all[1]['return_code'])->toBe(-1);
});

test('hasPendingCommand correctly identifies existing pending command', function () {
    $this->service->queueCommand('DEV_001', 'DATA USER PIN=500');

    expect($this->service->hasPendingCommand('DEV_001', 'DATA USER PIN=500'))->toBeTrue();
    expect($this->service->hasPendingCommand('DEV_001', 'DATA USER PIN=999'))->toBeFalse();
    expect($this->service->hasPendingCommand('DEV_002', 'DATA USER PIN=500'))->toBeFalse();
});

test('hasPendingCommand and hasPendingUserCommand treat SENT commands as active to prevent duplicates', function () {
    $cmd = $this->service->queueCommand('DEV_001', 'DATA USER PIN=500 Name=Test');
    $this->service->markCommandsAsSent([$cmd['id']]);

    // Should return true so identical command is not re-queued while in-flight
    expect($this->service->hasPendingCommand('DEV_001', 'DATA USER PIN=500 Name=Test'))->toBeTrue();
    expect($this->service->hasPendingUserCommand('DEV_001', 500))->toBeTrue();

    // queueCommand should return the existing command without appending a duplicate
    $duplicateAttempt = $this->service->queueCommand('DEV_001', 'DATA USER PIN=500 Name=Test');
    expect($duplicateAttempt['id'])->toBe($cmd['id']);

    $all = $this->service->getAllCommands('DEV_001');
    expect($all)->toHaveCount(1);
});

test('creates a new numbered file instead of clearing when size limit is reached and checks queue across all files', function () {
    // Create a service with a tiny size limit of 150 bytes to test file rotation
    $smallLimitService = new DeviceCommandService($this->testDir, 150);

    $smallLimitService->queueCommand('DEV_001', 'CMD_A_INITIAL_COMMAND');
    $devFile = $smallLimitService->getDeviceQueueFile('DEV_001');
    expect(file_exists($devFile))->toBeTrue();
    expect(filesize($devFile))->toBeGreaterThan(0);

    // Queue more commands so size exceeds 150 bytes and creates a numbered file
    $smallLimitService->queueCommand('DEV_001', 'CMD_B_LONGER_COMMAND_EXCEEDING_LIMIT_PADDING_1234567890');
    $smallLimitService->queueCommand('DEV_001', 'CMD_C_LONGER_COMMAND_EXCEEDING_LIMIT_PADDING_1234567890');

    $allFiles = $smallLimitService->getAllCommandFiles();
    expect(count($allFiles))->toBeGreaterThanOrEqual(2);

    // Verify existing commands are preserved (NOT cleared) and both files are checked
    $commands = $smallLimitService->getAllCommands();
    expect($commands)->toHaveCount(3);

    // Pending commands are also fetched across all numbered files in order
    $pending = $smallLimitService->getPendingCommands('DEV_001', 10);
    expect($pending)->toHaveCount(3);
    expect($pending[0]['command'])->toBe('CMD_A_INITIAL_COMMAND');

    // Clean up numbered files
    $smallLimitService->clearCommands();
});

test('automatically deletes queue file when all commands in it are SUCCESS and retains if pending', function () {
    $pruneService = new DeviceCommandService($this->testDir, 350);
    $pruneService->clearCommands();

    // Queue 4 commands that span across 2 files
    $pruneService->queueCommand('SN_TEST', 'DATA USER PIN=1');
    $pruneService->queueCommand('SN_TEST', 'DATA USER PIN=2');
    $pruneService->queueCommand('SN_TEST', 'DATA USER PIN=3');
    $pruneService->queueCommand('SN_TEST', 'DATA USER PIN=4');

    $allFiles = $pruneService->getAllCommandFilesForDevice('SN_TEST');
    expect(count($allFiles))->toBe(2);
    $cleanTestPath = $allFiles[0];
    $cleanTestPath1 = $allFiles[1];

    expect(file_exists($cleanTestPath))->toBeTrue();
    expect(file_exists($cleanTestPath1))->toBeTrue();

    // ACK commands 1 and 2 (command 3 is still pending in first file)
    $pruneService->recordCommandAck(1, 0);
    $pruneService->recordCommandAck(2, 0);

    // First file still has command 3 pending, so it must be retained
    expect(file_exists($cleanTestPath))->toBeTrue();
    expect(file_exists($cleanTestPath1))->toBeTrue();

    // ACK command 3 (all commands in first file are now SUCCESS)
    $pruneService->recordCommandAck(3, 0);

    // First file must now be automatically deleted!
    expect(file_exists($cleanTestPath))->toBeFalse();
    // Second file still has command 4 pending, so it must be retained
    expect(file_exists($cleanTestPath1))->toBeTrue();

    // ACK command 4 (all commands in second file are now SUCCESS)
    $pruneService->recordCommandAck(4, 0);

    // Second file must now be automatically deleted!
    expect(file_exists($cleanTestPath1))->toBeFalse();

    $pruneService->clearCommands();
});

test('auto-migrates existing JSON array file to NDJSON on queueCommand without dropping records', function () {
    $devFile = $this->service->getDeviceQueueFile('DEV_LEGACY');
    $legacyArray = [
        [
            'id' => 1,
            'device_sn' => 'DEV_LEGACY',
            'command' => 'DATA USER PIN=1',
            'status' => 'PENDING',
            'return_code' => null,
            'created_at' => '2026-09-10 11:00:00',
            'updated_at' => '2026-09-10 11:00:00',
        ],
        [
            'id' => 2,
            'device_sn' => 'DEV_LEGACY',
            'command' => 'DATA USER PIN=2',
            'status' => 'PENDING',
            'return_code' => null,
            'created_at' => '2026-09-10 11:00:00',
            'updated_at' => '2026-09-10 11:00:00',
        ],
    ];
    file_put_contents($devFile, json_encode($legacyArray, JSON_PRETTY_PRINT));

    // Queue a new command using the service
    $newCmd = $this->service->queueCommand('DEV_LEGACY', 'DATA USER PIN=3');
    expect($newCmd['id'])->toBe(3);

    // Verify all 3 commands exist and file is now NDJSON
    $all = $this->service->getAllCommands('DEV_LEGACY');
    expect($all)->toHaveCount(3);
    expect($all[0]['id'])->toBe(1);
    expect($all[1]['id'])->toBe(2);
    expect($all[2]['id'])->toBe(3);

    $pending = $this->service->getPendingCommands('DEV_LEGACY', 10);
    expect($pending)->toHaveCount(3);

    // Verify file content starts with '{' (NDJSON), NOT '[' (array)
    $firstChar = trim(file_get_contents($devFile))[0];
    expect($firstChar)->toBe('{');
});

test('safely appends when existing file has missing trailing newline', function () {
    $devFile = $this->service->getDeviceQueueFile('DEV_001');
    $record = json_encode([
        'id' => 1,
        'device_sn' => 'DEV_001',
        'command' => 'CMD 1',
        'status' => 'PENDING',
        'return_code' => null,
        'created_at' => '2026-09-16 10:00:00',
        'updated_at' => '2026-09-16 10:00:00',
    ]);
    file_put_contents($devFile, $record); // NO \n at end

    // Queue next command
    $cmd2 = $this->service->queueCommand('DEV_001', 'CMD 2');
    expect($cmd2['id'])->toBe(2);

    $lines = file($devFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect($lines)->toHaveCount(2);

    // Ensure line 1 and line 2 are both independently valid JSON
    $decoded1 = json_decode($lines[0], true);
    $decoded2 = json_decode($lines[1], true);
    expect($decoded1['id'])->toBe(1);
    expect($decoded2['id'])->toBe(2);
});

test('recordCommandAck handles multi-digit negative return code without corrupting JSON syntax', function () {
    $cmd = $this->service->queueCommand('DEV_001', 'CMD 1');
    expect($cmd['id'])->toBe(1);

    // Acknowledge with a 5-character negative return code: -1001
    $ackSuccess = $this->service->recordCommandAck($cmd['id'], -1001);
    expect($ackSuccess)->toBeTrue();

    // Verify file remains perfectly valid JSON
    $devFile = $this->service->getDeviceQueueFile('DEV_001');
    $rawContent = file_get_contents($devFile);
    $decoded = json_decode(trim($rawContent), true);
    expect($decoded)->toBeArray();
    expect($decoded['status'])->toBe('FAILED');
    expect($decoded['return_code'])->toBe(-1001);

    // Verify getAllCommands returns clean values
    $all = $this->service->getAllCommands('DEV_001');
    expect($all[0]['status'])->toBe('FAILED');
    expect($all[0]['return_code'])->toBe(-1001);
});

test('queueCommandsBatch atomically queues and deduplicates multiple entries in one call', function () {
    $entries = [
        ['device_sn' => 'DEV_A', 'command' => 'CMD 1'],
        ['device_sn' => 'DEV_B', 'command' => 'CMD 1'],
        ['device_sn' => 'DEV_A', 'command' => 'CMD 1'], // Duplicate in batch
        ['device_sn' => 'DEV_C', 'command' => 'CMD 2'],
    ];

    $count = $this->service->queueCommandsBatch($entries);
    expect($count)->toBe(3);

    $all = $this->service->getAllCommands();
    expect($all)->toHaveCount(3);
    expect($all[0]['device_sn'])->toBe('DEV_A');
    expect($all[1]['device_sn'])->toBe('DEV_B');
    expect($all[2]['device_sn'])->toBe('DEV_C');
});

test('normalizeFileIfNeeded and getPendingCommands operate with O(1) memory on large files', function () {
    $dev0File = $this->service->getDeviceQueueFile('DEV_0');
    $fp = fopen($dev0File, 'w');
    for ($i = 1; $i <= 5000; $i++) {
        $rec = [
            'id' => $i,
            'device_sn' => 'DEV_0',
            'command' => 'DATA USER PIN=' . $i . ' Name=User' . $i,
            'status' => $i === 5000 ? 'PENDING' : ($i === 4999 ? 'FAILED' : 'SUCCESS'),
            'return_code' => $i === 5000 ? null : ($i === 4999 ? -1 : 0),
            'created_at' => '2026-09-18 08:00:00',
            'updated_at' => '2026-09-18 08:00:00',
        ];
        fwrite($fp, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
    fclose($fp);

    $memBefore = memory_get_usage();
    $normalized = $this->service->normalizeFileIfNeeded($dev0File);
    $memAfter = memory_get_usage();

    expect($normalized)->toBeTrue();
    // Memory overhead must remain negligible (well below 200KB)
    expect($memAfter - $memBefore)->toBeLessThan(200000);

    // Fetch pending command from large file
    $pending = $this->service->getPendingCommands('DEV_0', 5);
    expect($pending)->toHaveCount(1);
    expect($pending[0]['id'])->toBe(5000);

    // Mark command as SENT using stream-based update
    $this->service->markCommandsAsSent([5000]);
    $pendingAfter = $this->service->getPendingCommands('DEV_0', 5);
    expect($pendingAfter)->toBeEmpty();

    // ACK command using stream-based update
    $ackSuccess = $this->service->recordCommandAck(5000, 0);
    expect($ackSuccess)->toBeTrue();

    // Verify record was updated to SUCCESS
    $all = $this->service->getAllCommands('DEV_0');
    $updated = collect($all)->firstWhere('id', 5000);
    expect($updated['status'])->toBe('SUCCESS');
    expect($updated['return_code'])->toBe(0);
});

test('getAllCommands with device_sn filter efficiently selects only matching records', function () {
    $this->service->queueCommand('DEV_TARGET', 'CMD TARGET 1');
    $this->service->queueCommand('DEV_OTHER', 'CMD OTHER 1');
    $this->service->queueCommand('DEV_TARGET', 'CMD TARGET 2');

    $targetCommands = $this->service->getAllCommands('DEV_TARGET');
    expect($targetCommands)->toHaveCount(2);
    expect($targetCommands[0]['command'])->toBe('CMD TARGET 1');
    expect($targetCommands[1]['command'])->toBe('CMD TARGET 2');

    $otherCommands = $this->service->getAllCommands('DEV_OTHER');
    expect($otherCommands)->toHaveCount(1);
    expect($otherCommands[0]['command'])->toBe('CMD OTHER 1');
});

test('queues commands into devicename_(serialnumber).json format and reads by serial number', function () {
    // Test custom device name mapping
    $ref = new ReflectionClass($this->service);
    $cacheProp = $ref->getProperty('deviceNameCache');
    $cacheProp->setAccessible(true);
    $cache = $cacheProp->getValue();
    $cache['UCR6254000010'] = 'ATTENDANCE 160';
    $cacheProp->setValue(null, $cache);

    expect($this->service->getDeviceFilePrefix('UCR6254000010'))->toBe('ATTENDANCE 160_(UCR6254000010)');

    $cmd = $this->service->queueCommand('UCR6254000010', 'DATA USER PIN=777');
    expect($cmd['id'])->toBeGreaterThan(0);

    $expectedFile = $this->service->getDeviceQueueFile('UCR6254000010');
    expect(basename($expectedFile))->toBe('ATTENDANCE 160_(UCR6254000010).json');
    expect(file_exists($expectedFile))->toBeTrue();

    // Verify polling by SN finds this file
    $polled = $this->service->getPendingCommands('UCR6254000010');
    expect($polled)->toHaveCount(1);
    expect($polled[0]['command'])->toBe('DATA USER PIN=777');
});

test('device polling finds command files by serial number pattern even if renamed in DB', function () {
    // Simulate an older queue file on disk that used an old name
    $oldFile = $this->service->getBaseDirectory() . DIRECTORY_SEPARATOR . 'OLD_DEPARTMENT_NAME_(DEV_RENAME_TEST).json';
    $rec = [
        'id' => 999,
        'device_sn' => 'DEV_RENAME_TEST',
        'command' => 'DATA USER PIN=999 Name=RenamedDeviceUser',
        'status' => 'PENDING',
        'return_code' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ];
    file_put_contents($oldFile, json_encode($rec) . "\n");

    // Device connects and requests by its serial number 'DEV_RENAME_TEST'
    $allFiles = $this->service->getAllCommandFilesForDevice('DEV_RENAME_TEST');
    expect($allFiles)->toContain($oldFile);

    $pending = $this->service->getPendingCommands('DEV_RENAME_TEST');
    expect($pending)->toHaveCount(1);
    expect($pending[0]['id'])->toBe(999);
    expect($pending[0]['command'])->toBe('DATA USER PIN=999 Name=RenamedDeviceUser');
});

test('markSentCommandsWithinDaysAsSuccess changes SENT commands within 3 days to SUCCESS and auto-deletes completed file', function () {
    $devFile = $this->service->getDeviceQueueFile('DEV_WITHIN_3DAYS');
    
    // Create 2 commands: 1 sent 2 days ago (within 3 days), 1 sent 1 day ago (within 3 days)
    $rec1 = [
        'id' => 101,
        'device_sn' => 'DEV_WITHIN_3DAYS',
        'command' => 'DATA USER PIN=101',
        'status' => 'SENT',
        'return_code' => null,
        'created_at' => now()->subDays(2)->toDateTimeString(),
        'updated_at' => now()->subDays(2)->toDateTimeString(),
    ];
    $rec2 = [
        'id' => 102,
        'device_sn' => 'DEV_WITHIN_3DAYS',
        'command' => 'DATA USER PIN=102',
        'status' => 'SENT',
        'return_code' => null,
        'created_at' => now()->subDays(1)->toDateTimeString(),
        'updated_at' => now()->subDays(1)->toDateTimeString(),
    ];

    file_put_contents($devFile, json_encode($rec1) . "\n" . json_encode($rec2) . "\n");
    expect(file_exists($devFile))->toBeTrue();

    // Run within 3 days resolution
    $updated = $this->service->markSentCommandsWithinDaysAsSuccess(3, 'DEV_WITHIN_3DAYS');
    expect($updated)->toBe(2);

    // Because all commands became SUCCESS, the file should be automatically deleted!
    expect(file_exists($devFile))->toBeFalse();
});

test('resolveSentCommandsAsSuccess respects older_than mode and leaves newer commands intact', function () {
    $devFile = $this->service->getDeviceQueueFile('DEV_OLDER_TEST');

    // 1 command from 5 days ago, 1 command from 1 day ago, 1 pending command
    $recOld = [
        'id' => 201,
        'device_sn' => 'DEV_OLDER_TEST',
        'command' => 'DATA USER PIN=201',
        'status' => 'SENT',
        'return_code' => null,
        'created_at' => now()->subDays(5)->toDateTimeString(),
        'updated_at' => now()->subDays(5)->toDateTimeString(),
    ];
    $recRecent = [
        'id' => 202,
        'device_sn' => 'DEV_OLDER_TEST',
        'command' => 'DATA USER PIN=202',
        'status' => 'SENT',
        'return_code' => null,
        'created_at' => now()->subDays(1)->toDateTimeString(),
        'updated_at' => now()->subDays(1)->toDateTimeString(),
    ];
    $recPending = [
        'id' => 203,
        'device_sn' => 'DEV_OLDER_TEST',
        'command' => 'DATA USER PIN=203',
        'status' => 'PENDING',
        'return_code' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ];

    file_put_contents($devFile, json_encode($recOld) . "\n" . json_encode($recRecent) . "\n" . json_encode($recPending) . "\n");

    // Only resolve commands older than 3 days
    $updated = $this->service->resolveSentCommandsAsSuccess(3, 'older_than', 'DEV_OLDER_TEST');
    expect($updated)->toBe(1);

    // File should NOT be deleted because recRecent is still SENT and recPending is PENDING
    expect(file_exists($devFile))->toBeTrue();

    $all = $this->service->getAllCommands('DEV_OLDER_TEST');
    expect($all)->toHaveCount(3);
    expect($all[0]['status'])->toBe('SUCCESS');
    expect($all[1]['status'])->toBe('SENT');
    expect($all[2]['status'])->toBe('PENDING');
});

