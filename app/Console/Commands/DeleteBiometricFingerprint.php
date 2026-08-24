<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use Illuminate\Console\Command;

class DeleteBiometricFingerprint extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:delete-finger 
                            {pin : Employee Biometric ID / PIN}
                            {fid : Finger ID (0-9) to remove}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a specific enrolled fingerprint from the database and dispatch deletion commands to all connected devices';

    public function handle(): int
    {
        $pin = (int)$this->argument('pin');
        $fid = $this->argument('fid');

        $this->info("Removing Finger ID {$fid} for PIN {$pin}...");

        $success = Biometrics::removeFingerprintTemplate($pin, $fid, true);

        if (!$success) {
            $this->error("Finger ID {$fid} was not found for PIN {$pin} or user does not exist.");
            return 1;
        }

        $this->info("Successfully deleted Finger ID {$fid} from database and queued DATA DELETE commands for all active connected devices.");
        return 0;
    }
}
