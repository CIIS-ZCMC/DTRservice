<?php

namespace App\Http\Controllers;

use App\Services\DtrReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DtrReportController extends Controller
{
    public function __construct(
        protected DtrReportService $dtrReportService
    ) {}

    /**
     * Generate DTR report (view in browser)
     */
    public function generate(Request $request, int $biometricId, int $year, int $month)
    {
        try {
            $data = $this->getReportData($biometricId, $year, $month, $request->boolean('refresh'), $request->query('whole_month', 1));

            if (empty($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data found for the specified employee and date range'
                ], 404);
            }

            $data = $this->prepareViewData($data);

            $pdf = Pdf::loadView('dtr.DTRview', $data)->setPaper('a4', 'portrait');
            return $pdf->stream("DTR_{$biometricId}_{$year}_{$month}.pdf");
        } catch (\Exception $e) {
            Log::error('Error generating DTR report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error generating report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download DTR report
     */
    public function download(Request $request, int $biometricId, int $year, int $month)
    {
        try {
            $data = $this->getReportData($biometricId, $year, $month, $request->boolean('refresh'), $request->query('whole_month', 1));

            if (empty($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data found for the specified employee and date range'
                ], 404);
            }
            $data = $this->prepareViewData($data);

            $empName = trim($data['employee']['name'] ?? 'Unknown');
            $monthName = strtoupper(date('F', strtotime($data['date_from'])));
            $filenameYear = date('Y', strtotime($data['date_from']));
            $halfLabel = ($request->query('whole_month', 1) == 0) ? ' (1-15)' : '';
            $filename = "{$empName} (DTR - {$monthName} {$filenameYear}{$halfLabel}).pdf";

            $pdf = Pdf::loadView('dtr.DTRview', $data)->setPaper('a4', 'portrait');
            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('Error downloading DTR report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error downloading report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Return DTR report as JSON
     */
    public function json(Request $request, int $biometricId, int $year, int $month)
    {
        try {
            $data = $this->getReportData($biometricId, $year, $month, $request->boolean('refresh'), $request->query('whole_month', 1));

            if (empty($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data found for the specified employee and date range'
                ], 404);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error generating JSON DTR report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error generating report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get report data from cache or generate fresh
     */
    private function getReportData(int $biometricId, int $year, int $month, bool $refresh = false, ?int $wholeMonth = null): array
    {
        $cacheKey = "dtr_report_{$biometricId}_{$year}_{$month}";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::get($cacheKey);

        if ($data === null) {
            $dateFrom = sprintf('%04d-%02d-01', $year, $month);
            $dateTo = date('Y-m-t', strtotime($dateFrom));

            $data = $this->dtrReportService->generateReport($biometricId, $dateFrom, $dateTo);

            if (!empty($data)) {
                Cache::put($cacheKey, $data, 3600);
            }
        }

        if (empty($data)) {
            return [];
        }

        if ($wholeMonth === 0 && !empty($data['daily_records'])) {
            $data['daily_records'] = array_values(array_filter($data['daily_records'], function ($record) {
                return (int)$record['day'] <= 15;
            }));
        }

        return $data;
    }

    /**
     * Prepare view data for PDF rendering
     */
    private function prepareViewData(array $data): array
    {
        $data['w_print'] = 1;
        $data['displayMonth'] = strtoupper(date('F', strtotime($data['date_from'])));
        $data['year'] = date('Y', strtotime($data['date_from']));
        $data['OHF'] = $data['hours'] ?? '';
        $data['Arrival_Departure'] = $data['arrival_departure'] ?? '';
        $data['dailyLogs'] = $data['daily_records'] ?? [];

        return $data;
    }
}
