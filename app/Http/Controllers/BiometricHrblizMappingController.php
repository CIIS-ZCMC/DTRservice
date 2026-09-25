<?php

namespace App\Http\Controllers;

use App\Models\Biometrics;
use App\Services\BiometricHrblizMatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BiometricHrblizMappingController extends Controller
{
    protected BiometricHrblizMatcherService $matcherService;

    public function __construct(BiometricHrblizMatcherService $matcherService)
    {
        $this->matcherService = $matcherService;
    }

    /**
     * Render the main HRBLIZ ID Mapping & Verification Page.
     */
    public function index(): View
    {
        $totalCount = Biometrics::count();
        $mappedCount = Biometrics::whereNotNull('hrbliz_biometric_id')->count();
        $unmappedCount = $totalCount - $mappedCount;
        $hasSample = file_exists(storage_path('app/hrbliz_sample.csv'));

        return view('biometrics.hrbliz', [
            'totalCount' => $totalCount,
            'mappedCount' => $mappedCount,
            'unmappedCount' => $unmappedCount,
            'hasSample' => $hasSample,
        ]);
    }

    /**
     * Get paginated and filtered list of biometrics for manual management.
     */
    public function list(Request $request): JsonResponse
    {
        $query = Biometrics::select('id', 'biometric_id', 'hrbliz_biometric_id', 'name', 'privilege');

        $search = trim($request->input('search', ''));
        if ($search !== '') {
            if (is_numeric($search)) {
                $intSearch = (int)$search;
                $query->where(function ($q) use ($intSearch) {
                    $q->where('biometric_id', $intSearch)
                      ->orWhere('hrbliz_biometric_id', $intSearch);
                });
            } else {
                $query->where('name', 'LIKE', "%{$search}%");
            }
        }

        $filter = $request->input('filter', 'all');
        if ($filter === 'mapped') {
            $query->whereNotNull('hrbliz_biometric_id');
        } elseif ($filter === 'unmapped') {
            $query->whereNull('hrbliz_biometric_id');
        }

        $sortBy = $request->input('sort_by', 'biometric_id');
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        
        if (in_array($sortBy, ['biometric_id', 'hrbliz_biometric_id', 'name'])) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('biometric_id', 'asc');
        }

        $perPage = min(max((int)$request->input('per_page', 50), 10), 250);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'stats' => [
                'total_system' => Biometrics::count(),
                'total_mapped' => Biometrics::whereNotNull('hrbliz_biometric_id')->count(),
                'total_unmapped' => Biometrics::whereNull('hrbliz_biometric_id')->count(),
            ],
        ]);
    }

    /**
     * Update a single biometric record's hrbliz_biometric_id.
     */
    public function updateSingle(Request $request): JsonResponse
    {
        $request->validate([
            'biometric_id' => 'required|integer',
            'hrbliz_biometric_id' => 'nullable|integer',
        ]);

        $pin = (int)$request->input('biometric_id');
        $rawHrbliz = $request->input('hrbliz_biometric_id');
        $hrblizId = is_numeric($rawHrbliz) ? (int)$rawHrbliz : null;

        $result = $this->matcherService->updateSingle($pin, $hrblizId);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * Analyze uploaded Excel/CSV file or pasted text and match with biometrics.
     */
    public function analyze(Request $request): JsonResponse
    {
        try {
            $parsedRows = [];

            if ($request->boolean('use_sample')) {
                $samplePath = storage_path('app/hrbliz_sample.csv');
                if (!file_exists($samplePath)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sample HRBLIZ CSV file not found on server.',
                    ], 404);
                }
                $parsedRows = $this->matcherService->parseInput($samplePath);
            } elseif ($request->hasFile('file')) {
                $file = $request->file('file');
                $parsedRows = $this->matcherService->parseInput($file);
            } elseif ($request->filled('raw_csv')) {
                $rawCsv = (string)$request->input('raw_csv');
                $parsedRows = $this->matcherService->parseInput(null, $rawCsv);
            } elseif ($request->has('rows') && is_array($request->input('rows'))) {
                // Client-side parsed rows (e.g. via SheetJS)
                $rawRows = $request->input('rows');
                foreach ($rawRows as $r) {
                    $ac = $r['ac_no'] ?? $r['AC No.'] ?? $r['pin'] ?? null;
                    $name = $r['name'] ?? $r['Name'] ?? null;
                    if ($ac !== null && is_numeric($ac) && $name) {
                        $parsedRows[] = [
                            'ac_no' => (int)$ac,
                            'name' => trim((string)$name),
                        ];
                    }
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide an Excel file (.xlsx), CSV file, or paste CSV text to analyze.',
                ], 422);
            }

            if (empty($parsedRows)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid records could be parsed. Ensure the file or text contains AC No. and Name columns.',
                ], 422);
            }

            $excludeAssigned = $request->boolean('exclude_assigned', true);
            $analysis = $this->matcherService->matchAll($parsedRows, $excludeAssigned);

            $msg = "Successfully analyzed {$analysis['summary']['total_rows']} records. Found {$analysis['summary']['matched_count']} matches.";
            if (!empty($analysis['summary']['already_assigned_count'])) {
                $msg .= " ({$analysis['summary']['already_assigned_count']} records with HRBLIZ ID already assigned were excluded from the verification queue).";
            }

            return response()->json([
                'success' => true,
                'message' => $msg,
                'summary' => $analysis['summary'],
                'results' => $analysis['results'],
                'unmatched_system_biometrics' => $analysis['unmatched_system_biometrics'],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Analysis failed: ' . $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch merge verified mappings into the database.
     */
    public function batchMerge(Request $request): JsonResponse
    {
        $request->validate([
            'mappings' => 'required|array|min:1',
            'mappings.*.biometric_id' => 'required|integer',
            'mappings.*.hrbliz_biometric_id' => 'required|integer',
        ]);

        $mappings = $request->input('mappings');
        $result = $this->matcherService->batchMerge($mappings);

        return response()->json($result, $result['success'] ? 200 : 500);
    }

    /**
     * Search biometrics database for modal candidate selection.
     */
    public function searchCandidates(Request $request): JsonResponse
    {
        $query = (string)$request->input('q', '');
        $limit = min(max((int)$request->input('limit', 15), 5), 50);

        $results = $this->matcherService->searchBiometrics($query, $limit);

        return response()->json([
            'success' => true,
            'candidates' => $results,
        ]);
    }

    /**
     * Return sample data for 1-click loading.
     */
    public function getSampleData(): JsonResponse
    {
        $samplePath = storage_path('app/hrbliz_sample.csv');
        if (!file_exists($samplePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Sample CSV file not available.',
            ], 404);
        }

        $lines = file($samplePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $totalRows = max(0, count($lines) - 1);
        $preview = array_slice($lines, 0, 15);

        return response()->json([
            'success' => true,
            'total_rows' => $totalRows,
            'preview_lines' => $preview,
            'filename' => 'hrbliz_sample.csv',
        ]);
    }
}
