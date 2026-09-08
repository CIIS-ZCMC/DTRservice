<?php

use App\Services\DeviceCommandService;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->testFilePath = storage_path('app/test_device_commands.json');
    $this->service = new DeviceCommandService($this->testFilePath);
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

test('creates a new numbered file instead of clearing when size limit is reached and checks queue across all files', function () {
    // Create a service with a tiny size limit of 150 bytes to test file rotation
    $smallLimitService = new DeviceCommandService($this->testFilePath, 150);

    $smallLimitService->queueCommand('DEV_001', 'CMD_A_INITIAL_COMMAND');
    expect(file_exists($this->testFilePath))->toBeTrue();
    expect(filesize($this->testFilePath))->toBeGreaterThan(0);

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
    $cleanTestPath = storage_path('framework/testing/test_auto_prune.json');
    $cleanTestPath1 = storage_path('framework/testing/test_auto_prune_1.json');
    @unlink($cleanTestPath);
    @unlink($cleanTestPath1);

    $pruneService = new DeviceCommandService($cleanTestPath, 350);

    // Queue 4 commands that span across 2 files
    $pruneService->queueCommand('SN_TEST', 'DATA USER PIN=1');
    $pruneService->queueCommand('SN_TEST', 'DATA USER PIN=2');
    $pruneService->queueCommand('SN_TEST', 'DATA USER PIN=3');
    $pruneService->queueCommand('SN_TEST', 'DATA USER PIN=4');

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

    @unlink($cleanTestPath);
    @unlink($cleanTestPath1);
});

