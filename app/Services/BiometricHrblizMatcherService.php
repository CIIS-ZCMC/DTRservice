<?php

namespace App\Services;

use App\Models\Biometrics;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BiometricHrblizMatcherService
{
    private array $biometrics = [];
    private array $tokenIndex = [];
    private array $pinIndex = [];
    private array $normIndex = [];
    private bool $indexed = false;

    /**
     * Initialize and load in-memory index of all biometrics records.
     */
    public function loadIndex(): void
    {
        if ($this->indexed) {
            return;
        }

        $records = Biometrics::select('id', 'biometric_id', 'hrbliz_biometric_id', 'name')
            ->get();

        foreach ($records as $b) {
            $bioData = [
                'id' => $b->id,
                'biometric_id' => (int)$b->biometric_id,
                'hrbliz_biometric_id' => $b->hrbliz_biometric_id !== null ? (int)$b->hrbliz_biometric_id : null,
                'name' => $b->name,
                'norm' => self::normalizeName($b->name),
                'tokens' => self::nameToTokens($b->name),
            ];

            $this->biometrics[$b->id] = $bioData;
            $this->pinIndex[$bioData['biometric_id']] = $b->id;

            if ($bioData['norm'] !== '') {
                $this->normIndex[$bioData['norm']][] = $b->id;
            }

            foreach ($bioData['tokens'] as $t) {
                $this->tokenIndex[$t][] = $b->id;
            }
        }

        $this->indexed = true;
    }

    /**
     * Normalize name for robust matching:
     * - Uppercase
     * - Remove punctuation and special characters
     * - Strip common suffixes (JR, SR, II, III, IV, V)
     * - Consolidate spaces
     */
    public static function normalizeName(?string $name): string
    {
        if (!$name) {
            return '';
        }
        $name = mb_strtoupper(trim($name), 'UTF-8');
        $name = str_replace([',', '.', '-', '"', "'", '`', '’', '(', ')', '/', '\\', '#'], ' ', $name);
        $name = preg_replace('/\b(JR|SR|II|III|IV|V)\b/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    /**
     * Extract unique significant word tokens (words >= 2 characters).
     */
    public static function nameToTokens(?string $name): array
    {
        $norm = self::normalizeName($name);
        $parts = explode(' ', $norm);
        $tokens = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (strlen($p) > 1) { // Ignore single-character middle initials like "P"
                $tokens[] = $p;
            }
        }
        sort($tokens);
        return array_values(array_unique($tokens));
    }

    /**
     * Parse raw text, CSV, or uploaded file into structured array of rows:
     * [ ['ac_no' => 1001, 'name' => 'John Doe', 'raw' => '...'], ... ]
     */
    public function parseInput(UploadedFile|string|null $input, ?string $rawText = null): array
    {
        $lines = [];

        if ($input instanceof UploadedFile) {
            $ext = strtolower($input->getClientOriginalExtension());
            if ($ext === 'xlsx') {
                return $this->parseXlsxFile($input->getPathname());
            }

            // CSV, TSV, or TXT
            $content = file_get_contents($input->getPathname());
            $lines = preg_split('/\r\n|\r|\n/', $content);
        } elseif (is_string($input) && file_exists($input)) {
            $ext = strtolower(pathinfo($input, PATHINFO_EXTENSION));
            if ($ext === 'xlsx') {
                return $this->parseXlsxFile($input);
            }
            $content = file_get_contents($input);
            $lines = preg_split('/\r\n|\r|\n/', $content);
        } elseif (!empty($rawText)) {
            $lines = preg_split('/\r\n|\r|\n/', $rawText);
        }

        return $this->parseCsvLines($lines);
    }

    /**
     * Parse CSV lines with automatic column detection (handles quotes, commas, tabs).
     */
    private function parseCsvLines(array $lines): array
    {
        $rows = [];
        $acCol = null;
        $nameCol = null;
        $isFirst = true;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Determine delimiter: comma, semicolon, or tab
            $delimiter = ',';
            if (str_contains($line, "\t") && substr_count($line, "\t") >= substr_count($line, ',')) {
                $delimiter = "\t";
            } elseif (substr_count($line, ';') > substr_count($line, ',')) {
                $delimiter = ';';
            }

            $cols = str_getcsv($line, $delimiter);
            $cols = array_map('trim', $cols);

            // Detect header row if present
            if ($isFirst) {
                $isFirst = false;
                $firstRowHeader = false;

                foreach ($cols as $idx => $c) {
                    $lower = strtolower($c);
                    if (str_contains($lower, 'ac no') || str_contains($lower, 'ac_no') || str_contains($lower, 'pin') || str_contains($lower, 'user id') || str_contains($lower, 'bio id')) {
                        $acCol = $idx;
                        $firstRowHeader = true;
                    }
                    if (str_contains($lower, 'name') || str_contains($lower, 'employee')) {
                        $nameCol = $idx;
                        $firstRowHeader = true;
                    }
                }

                if ($firstRowHeader) {
                    // Header detected, skip header row
                    continue;
                }

                // If not explicit header, default: AC No is col 0, Name is col 2 (or col 1)
                $acCol = 0;
                $nameCol = count($cols) >= 3 ? 2 : 1;
            }

            $colCount = count($cols);
            $targetAcCol = $acCol ?? 0;
            $targetNameCol = $nameCol ?? ($colCount >= 3 ? 2 : 1);

            $acVal = $cols[$targetAcCol] ?? null;
            $nameVal = $cols[$targetNameCol] ?? null;

            // If name is empty in selected column, check other columns for text
            if (!$nameVal || is_numeric($nameVal)) {
                for ($i = 0; $i < $colCount; $i++) {
                    if ($i !== $targetAcCol && !empty($cols[$i]) && !is_numeric($cols[$i])) {
                        $nameVal = $cols[$i];
                        break;
                    }
                }
            }

            // Check if acVal is numeric (or contains numbers)
            if ($acVal !== null && preg_match('/\d+/', $acVal, $m)) {
                $numericAc = (int)$m[0];
            } else {
                continue; // Skip lines without numeric AC / ID
            }

            if (!$nameVal) {
                continue;
            }

            $rows[] = [
                'ac_no' => $numericAc,
                'name' => trim($nameVal, "\"' \t\n\r"),
                'raw' => $line,
            ];
        }

        return $rows;
    }

    /**
     * Parse XLSX file natively using PHP ZipArchive without external libraries.
     */
    private function parseXlsxFile(string $filePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \Exception("Unable to open XLSX archive: {$filePath}");
        }

        $sharedStrings = [];
        $ssIndex = $zip->locateName('xl/sharedStrings.xml');
        if ($ssIndex !== false) {
            $xmlStr = $zip->getFromIndex($ssIndex);
            $xml = @simplexml_load_string($xmlStr);
            if ($xml) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $txt = '';
                        foreach ($si->r as $r) {
                            $txt .= (string)$r->t;
                        }
                        $sharedStrings[] = $txt;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        $sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml');
        if ($sheetIndex === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (preg_match('#xl/worksheets/sheet\d+\.xml#i', $stat['name'])) {
                    $sheetIndex = $i;
                    break;
                }
            }
        }

        if ($sheetIndex === false) {
            $zip->close();
            throw new \Exception("Worksheet xml not found in XLSX file.");
        }

        $sheetXmlStr = $zip->getFromIndex($sheetIndex);
        $sheetXml = @simplexml_load_string($sheetXmlStr);
        $zip->close();

        if (!$sheetXml) {
            throw new \Exception("Failed to parse worksheet XML in XLSX.");
        }

        $rawGrid = [];
        foreach ($sheetXml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $ref = (string)$cell['r'];
                preg_match('/([A-Z]+)(\d+)/', $ref, $matches);
                $colLetter = $matches[1] ?? 'A';
                
                $colIdx = 0;
                $len = strlen($colLetter);
                for ($k = 0; $k < $len; $k++) {
                    $colIdx = $colIdx * 26 + (ord($colLetter[$k]) - ord('A') + 1);
                }
                $colIdx--;

                $type = (string)$cell['t'];
                $val = (string)$cell->v;

                if ($type === 's') {
                    $val = $sharedStrings[(int)$val] ?? '';
                } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                    $val = (string)$cell->is->t;
                }

                $rowData[$colIdx] = trim($val);
            }
            ksort($rowData);
            if (!empty(array_filter($rowData))) {
                $rawGrid[] = array_values($rowData);
            }
        }

        // Convert grid to rows
        $csvLines = [];
        foreach ($rawGrid as $r) {
            $csvLines[] = implode(',', array_map(function ($c) {
                return '"' . str_replace('"', '""', $c) . '"';
            }, $r));
        }

        return $this->parseCsvLines($csvLines);
    }

    /**
     * Match parsed Excel/CSV rows against current biometrics database.
     */
    public function matchAll(array $parsedRows): array
    {
        $this->loadIndex();

        $results = [];
        $matchedBiometricIds = [];
        $stats = [
            'total_rows' => count($parsedRows),
            'exact' => 0,
            'high' => 0,
            'possible' => 0,
            'unmatched' => 0,
        ];

        foreach ($parsedRows as $row) {
            $acNo = (int)$row['ac_no'];
            $excelName = (string)$row['name'];

            $matchResult = $this->matchSingle($acNo, $excelName);
            $status = $matchResult['status'];
            $stats[$status]++;

            if (!empty($matchResult['matched_biometric'])) {
                $matchedBiometricIds[$matchResult['matched_biometric']['biometric_id']] = true;
            }

            $results[] = [
                'excel_ac_no' => $acNo,
                'excel_name' => $excelName,
                'status' => $status,
                'confidence' => $matchResult['confidence'],
                'method' => $matchResult['method'],
                'matched_biometric' => $matchResult['matched_biometric'],
                'candidate_alternatives' => $matchResult['candidate_alternatives'],
                'selected_biometric_id' => $matchResult['matched_biometric']['biometric_id'] ?? null,
                'proposed_hrbliz_id' => $acNo,
            ];
        }

        // Find biometrics in system that were NOT matched by any Excel record
        $unmatchedBiometrics = [];
        foreach ($this->biometrics as $b) {
            if (!isset($matchedBiometricIds[$b['biometric_id']])) {
                $unmatchedBiometrics[] = [
                    'biometric_id' => $b['biometric_id'],
                    'name' => $b['name'],
                    'hrbliz_biometric_id' => $b['hrbliz_biometric_id'],
                ];
            }
        }

        return [
            'summary' => [
                'total_rows' => $stats['total_rows'],
                'matched_count' => $stats['exact'] + $stats['high'] + $stats['possible'],
                'exact_count' => $stats['exact'],
                'high_count' => $stats['high'],
                'possible_count' => $stats['possible'],
                'unmatched_count' => $stats['unmatched'],
                'total_system_biometrics' => count($this->biometrics),
                'unmatched_system_biometrics_count' => count($unmatchedBiometrics),
            ],
            'results' => $results,
            'unmatched_system_biometrics' => $unmatchedBiometrics,
        ];
    }

    /**
     * Match a single Excel row against all indexed biometrics.
     */
    public function matchSingle(int $excelAcNo, string $excelName): array
    {
        $this->loadIndex();

        $excelNorm = self::normalizeName($excelName);
        $excelTokens = self::nameToTokens($excelName);

        // 1. Exact normalized name match
        if (isset($this->normIndex[$excelNorm])) {
            $matchedId = $this->normIndex[$excelNorm][0];
            $matched = $this->biometrics[$matchedId];
            return [
                'status' => 'exact',
                'confidence' => 100,
                'method' => 'Exact Name Match',
                'matched_biometric' => $matched,
                'candidate_alternatives' => [],
            ];
        }

        // 2. Candidate gathering via token inverted index + PIN match
        $candidateScores = [];

        foreach ($excelTokens as $t) {
            if (isset($this->tokenIndex[$t])) {
                foreach ($this->tokenIndex[$t] as $bioId) {
                    $candidateScores[$bioId] = ($candidateScores[$bioId] ?? 0) + 1;
                }
            }
        }

        // PIN match bonus candidate
        if (isset($this->pinIndex[$excelAcNo])) {
            $bioId = $this->pinIndex[$excelAcNo];
            $candidateScores[$bioId] = ($candidateScores[$bioId] ?? 0) + 1;
        }

        $evaluated = [];

        foreach ($candidateScores as $bioId => $sharedHits) {
            $bio = $this->biometrics[$bioId];
            $bioTokens = $bio['tokens'];

            $intersect = array_intersect($excelTokens, $bioTokens);
            $intersectCount = count($intersect);
            $union = array_unique(array_merge($excelTokens, $bioTokens));
            $unionCount = count($union);

            if ($unionCount === 0) {
                continue;
            }

            $score = ($intersectCount / $unionCount) * 100;
            $method = 'Token Overlap';

            // Subset check: all meaningful tokens of one are inside the other
            $isSubset = ($intersectCount >= 2 && ($intersectCount === count($excelTokens) || $intersectCount === count($bioTokens)));
            if ($isSubset) {
                $score = max($score, 90 + min(9, $intersectCount * 2));
                $method = 'High Token Overlap';
            }

            // Same PIN bonus if name shares at least 1 token (e.g. surname)
            if ($bio['biometric_id'] === $excelAcNo && $intersectCount >= 1) {
                $score = max($score, 92 + ($intersectCount * 2));
                $method = 'Same PIN & Shared Name';
            }

            // String similarity for minor typos (e.g. Eugine vs Eugene)
            if ($score < 80 && $intersectCount >= 1 && count($excelTokens) >= 2 && count($bioTokens) >= 2) {
                similar_text($excelNorm, $bio['norm'], $simPercent);
                if ($simPercent >= 75) {
                    $score = max($score, $simPercent);
                    $method = 'Fuzzy Name Overlap';
                }
            }

            $evaluated[] = [
                'bio' => $bio,
                'score' => round($score, 1),
                'method' => $method,
            ];
        }

        // Sort candidates by score descending
        usort($evaluated, fn($a, $b) => $b['score'] <=> $a['score']);

        if (!empty($evaluated) && $evaluated[0]['score'] >= 60) {
            $top = $evaluated[0];
            $status = ($top['score'] >= 85) ? 'high' : 'possible';

            // Up to 3 alternative candidates
            $alternatives = [];
            for ($i = 1; $i < min(4, count($evaluated)); $i++) {
                if ($evaluated[$i]['score'] >= 50) {
                    $alternatives[] = [
                        'biometric_id' => $evaluated[$i]['bio']['biometric_id'],
                        'name' => $evaluated[$i]['bio']['name'],
                        'score' => $evaluated[$i]['score'],
                        'method' => $evaluated[$i]['method'],
                    ];
                }
            }

            return [
                'status' => $status,
                'confidence' => $top['score'],
                'method' => $top['method'],
                'matched_biometric' => $top['bio'],
                'candidate_alternatives' => $alternatives,
            ];
        }

        // Unmatched fallback: provide top 2 nearest candidates if any
        $alternatives = [];
        for ($i = 0; $i < min(2, count($evaluated)); $i++) {
            $alternatives[] = [
                'biometric_id' => $evaluated[$i]['bio']['biometric_id'],
                'name' => $evaluated[$i]['bio']['name'],
                'score' => $evaluated[$i]['score'],
                'method' => $evaluated[$i]['method'],
            ];
        }

        return [
            'status' => 'unmatched',
            'confidence' => 0,
            'method' => 'None',
            'matched_biometric' => null,
            'candidate_alternatives' => $alternatives,
        ];
    }

    /**
     * Search biometrics database for candidate selection modal.
     */
    public function searchBiometrics(string $query, int $limit = 20): array
    {
        $q = trim($query);
        if ($q === '') {
            return Biometrics::select('id', 'biometric_id', 'hrbliz_biometric_id', 'name')
                ->limit($limit)
                ->get()
                ->toArray();
        }

        $builder = Biometrics::select('id', 'biometric_id', 'hrbliz_biometric_id', 'name');

        if (is_numeric($q)) {
            $intQ = (int)$q;
            $builder->where(function ($sub) use ($intQ) {
                $sub->where('biometric_id', $intQ)
                    ->orWhere('hrbliz_biometric_id', $intQ);
            });
        } else {
            $builder->where('name', 'LIKE', "%{$q}%");
        }

        return $builder->limit($limit)->get()->toArray();
    }

    /**
     * Update single biometric record's hrbliz_biometric_id.
     */
    public function updateSingle(int $biometricId, ?int $hrblizId): array
    {
        $record = Biometrics::where('biometric_id', $biometricId)->first();

        if (!$record) {
            return [
                'success' => false,
                'message' => "Biometric record with PIN {$biometricId} not found.",
            ];
        }

        $prevHrblizId = $record->hrbliz_biometric_id;
        $record->hrbliz_biometric_id = $hrblizId;
        $record->saveQuietly();

        Log::info("Manual HRBLIZ ID Update", [
            'biometric_id' => $biometricId,
            'name' => $record->name,
            'previous_hrbliz_id' => $prevHrblizId,
            'new_hrbliz_id' => $hrblizId,
        ]);

        return [
            'success' => true,
            'biometric_id' => $biometricId,
            'hrbliz_biometric_id' => $hrblizId,
            'name' => $record->name,
            'message' => $hrblizId !== null 
                ? "Successfully assigned HRBLIZ ID {$hrblizId} to {$record->name} (PIN {$biometricId})." 
                : "Cleared HRBLIZ ID for {$record->name} (PIN {$biometricId}).",
        ];
    }

    /**
     * Batch merge array of confirmed mappings.
     * $mappings: [ ['biometric_id' => 141, 'hrbliz_biometric_id' => 4017], ... ]
     */
    public function batchMerge(array $mappings): array
    {
        if (empty($mappings)) {
            return [
                'success' => false,
                'message' => 'No mappings provided for merge.',
                'updated_count' => 0,
            ];
        }

        $updatedCount = 0;
        $failedCount = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($mappings as $item) {
                $pin = (int)($item['biometric_id'] ?? 0);
                $hrblizId = is_numeric($item['hrbliz_biometric_id'] ?? null) 
                    ? (int)$item['hrbliz_biometric_id'] 
                    : null;

                if ($pin <= 0) {
                    continue;
                }

                $affected = Biometrics::where('biometric_id', $pin)
                    ->update(['hrbliz_biometric_id' => $hrblizId]);

                if ($affected) {
                    $updatedCount++;
                } else {
                    $failedCount++;
                    $errors[] = "PIN {$pin} not found in database.";
                }
            }

            DB::commit();

            Log::info("Batch HRBLIZ Merge Completed", [
                'updated_count' => $updatedCount,
                'failed_count' => $failedCount,
            ]);

            return [
                'success' => true,
                'updated_count' => $updatedCount,
                'failed_count' => $failedCount,
                'errors' => array_slice($errors, 0, 10),
                'message' => "Successfully merged {$updatedCount} HRBLIZ Biometric IDs into the database.",
            ];
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error("Batch HRBLIZ Merge Failed: " . $th->getMessage());

            return [
                'success' => false,
                'message' => "Batch merge failed: " . $th->getMessage(),
                'updated_count' => 0,
            ];
        }
    }
}
