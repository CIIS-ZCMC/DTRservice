<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use Illuminate\Console\Command;

class CheckDuplicateBiometrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:find-duplicates 
                            {pin? : Target Employee Biometric ID / PIN to check}
                            {--all : Audit entire database for any duplicate templates across all PINs}
                            {--json : Output result in raw JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Search the biometrics table for identical/duplicate fingerprint templates against a specified PIN or across all enrolled employees';

    public function handle(): int
    {
        $pin = $this->argument('pin');
        $all = (bool)$this->option('all');
        $json = (bool)$this->option('json');

        if (!$pin && !$all) {
            $this->error('Please provide an Employee Biometric PIN or use the --all flag to audit the entire database.');
            $this->line('Usage:');
            $this->line('  php artisan biometrics:find-duplicates 2479');
            $this->line('  php artisan biometrics:find-duplicates 2479 --json');
            $this->line('  php artisan biometrics:find-duplicates --all');
            return 1;
        }

        if ($all) {
            return $this->handleAllScan($json);
        }

        return $this->handleSinglePinScan((int)$pin, $json);
    }

    protected function handleSinglePinScan(int $pin, bool $json): int
    {
        $result = Biometrics::findDuplicateTemplates($pin);

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return ($result['success'] ?? false) ? 0 : 1;
        }

        if (!($result['success'] ?? false)) {
            $this->error($result['error'] ?? "An error occurred while inspecting PIN {$pin}.");
            return 1;
        }

        $this->newLine();
        $this->info("========================================================================================");
        $this->info("   BIOMETRIC TEMPLATE DUPLICATE FINDER — PIN {$pin}");
        $this->info("========================================================================================");
        $this->line("<fg=cyan;options=bold>Target Employee Details:</>");
        $this->line(" • PIN:              <fg=yellow>{$result['target_pin']}</>");
        $this->line(" • Name:             <fg=white;options=bold>{$result['target_name']}</>");
        $this->line(" • Enrolled Fingers: <fg=green>{$result['enrolled_templates_count']}</>");

        if ($result['enrolled_templates_count'] === 0) {
            $this->newLine();
            $this->warn("⚠️  {$result['message']}");
            return 0;
        }

        // Show enrolled finger templates table
        $this->newLine();
        $this->line("<fg=cyan;options=bold>Enrolled Fingerprint Templates on PIN {$pin}:</>");
        $enrolledRows = [];
        foreach ($result['enrolled_fingers'] as $f) {
            $enrolledRows[] = [
                $f['finger_id'],
                $f['finger_name'],
                strtoupper($f['version']),
                $f['size'] . ' bytes',
                $f['preview'],
            ];
        }
        $this->table(['Slot ID', 'Finger Name', 'Algorithm', 'Size', 'Template Preview'], $enrolledRows);

        // Internal duplicates (same person enrolled same template on multiple slots)
        if (!empty($result['internal_duplicates'])) {
            $this->newLine();
            $this->warn("⚠️  INTERNAL SAME-PIN DUPLICATE DETECTED:");
            $this->line("The employee has the EXACT same fingerprint enrolled on multiple finger slots on their own PIN:");
            $internalRows = [];
            foreach ($result['internal_duplicates'] as $intDup) {
                $internalRows[] = [
                    "Slot {$intDup['finger_id_1']} ({$intDup['finger_name_1']})",
                    "Slot {$intDup['finger_id_2']} ({$intDup['finger_name_2']})",
                    strtoupper($intDup['algorithm']),
                    $intDup['size'] . ' bytes',
                    'Exact 100% Identical',
                ];
            }
            $this->table(['Finger Slot 1', 'Finger Slot 2', 'Algorithm', 'Size', 'Match Type'], $internalRows);
        }

        // External duplicates (matches with other PINs in the database)
        if (empty($result['duplicates'])) {
            $this->newLine();
            $this->info("✅ SUCCESS: No duplicate templates found in the database for PIN {$pin}.");
            $this->line("All {$result['enrolled_templates_count']} enrolled fingerprint template(s) are completely unique across all registered employees.");
            $this->newLine();
            return 0;
        }

        $this->newLine();
        $this->error("🚨 CRITICAL: IDENTICAL TEMPLATE(S) FOUND ACROSS OTHER PINs!");
        $this->line("The following registered employee(s) share 100% identical biometric fingerprint templates with PIN {$pin}:");
        $this->newLine();

        $dupRows = [];
        foreach ($result['duplicates'] as $dup) {
            $sameSlotText = $dup['is_same_finger_slot'] ? '<fg=green>YES</>' : '<fg=yellow>NO (Cross-Slot)</>';
            $dupRows[] = [
                "Slot {$dup['target_finger_id']} ({$dup['target_finger_name']})",
                $dup['matched_pin'],
                $dup['matched_name'],
                "Slot {$dup['matched_finger_id']} ({$dup['matched_finger_name']})",
                $sameSlotText,
                strtoupper($dup['algorithm']),
                $dup['size'] . ' bytes',
                '<fg=red;options=bold>100% Identical</>',
            ];
        }

        $this->table([
            "Target PIN {$pin} Finger",
            'Matched PIN',
            'Matched Employee Name',
            'Matched Finger Slot',
            'Same Slot?',
            'Algorithm',
            'Size',
            'Match Status'
        ], $dupRows);

        $this->newLine();
        $this->line("<fg=yellow;options=bold>Recommended Actions:</>");
        $this->line(" • Verify employee identity: Check if the two PINs represent the same person registered twice or a ghost enrollment.");
        $this->line(" • If a template was accidentally enrolled or duplicated on another PIN, delete it via:");
        $this->line("   <fg=cyan>php artisan biometrics:delete-finger <PIN> <FID></>");
        $this->newLine();

        return 0;
    }

    protected function handleAllScan(bool $json): int
    {
        $this->info("Scanning entire biometrics table for duplicate templates...");
        $groups = Biometrics::findAllDuplicateTemplates();

        if ($json) {
            $this->line(json_encode([
                'success' => true,
                'total_duplicate_clusters' => count($groups),
                'clusters' => $groups,
            ], JSON_PRETTY_PRINT));
            return 0;
        }

        $this->newLine();
        $this->info("========================================================================================");
        $this->info("   FULL DATABASE BIOMETRIC DUPLICATE AUDIT REPORT");
        $this->info("========================================================================================");

        if (empty($groups)) {
            $this->info("✅ SUCCESS: No duplicate fingerprint templates found across the entire database!");
            $this->newLine();
            return 0;
        }

        $this->error("🚨 FOUND " . count($groups) . " DUPLICATE TEMPLATE CLUSTER(S) IN THE DATABASE:");
        $this->newLine();

        foreach ($groups as $idx => $group) {
            $clusterNum = $idx + 1;
            $this->line("<fg=cyan;options=bold>Cluster #{$clusterNum}:</> Template Hash <fg=yellow>{$group['template_hash']}</> | Algorithm: " . strtoupper($group['algorithm']) . " | Size: {$group['size']} bytes | Total Involved PINs: " . count($group['pins_involved']));
            
            $rows = [];
            foreach ($group['entries'] as $entry) {
                $rows[] = [
                    $entry['pin'],
                    $entry['name'],
                    "Slot {$entry['finger_id']} ({$entry['finger_name']})",
                    strtoupper($entry['version']),
                    $entry['size'] . ' bytes',
                ];
            }
            $this->table(['PIN', 'Employee Name', 'Finger Slot', 'Algorithm', 'Size'], $rows);
            $this->newLine();
        }

        return 0;
    }
}
