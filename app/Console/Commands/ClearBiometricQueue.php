<?php

namespace App\Console\Commands;

use App\Services\DeviceCommandService;
use Illuminate\Console\Command;

class ClearBiometricQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:clear-queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Instantly stop and clear all pending device commands across all queue files';

    public function handle(DeviceCommandService $commandService): int
    {
        $commandService->clearCommands();

        $this->info('Successfully cleared all device command queue files!');
        $this->line('<fg=green>All biometric devices will immediately stop receiving commands on their next poll.</>');

        return 0;
    }
}