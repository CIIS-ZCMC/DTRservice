<?php

namespace App\Http\Controllers;

use App\Services\DtrReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DtrReportController extends Controller
{
    public function __construct(
        protected DtrReportService $dtrReportService
    ) {}

    /**
     * Generate DTR report (view in browser)
     */
    public function generate(int $biometricId, string $dateFrom, string $dateTo)
    {
        try {
            $data = $this->dtrReportService->generateReport($biometricId, $dateFrom, $dateTo);

            if (empty($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data found for the specified employee and date range'
                ], 404);
            }

            $pdf = Pdf::loadView('dtr.report', $data);
            return $pdf->stream("DTR_{$biometricId}_{$dateFrom}_to_{$dateTo}.pdf");
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
    public function download(int $biometricId, string $dateFrom, string $dateTo)
    {
        try {
            $data = $this->dtrReportService->generateReport($biometricId, $dateFrom, $dateTo);

            if (empty($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data found for the specified employee and date range'
                ], 404);
            }

            $pdf = Pdf::loadView('dtr.report', $data);
            return $pdf->download("DTR_{$biometricId}_{$dateFrom}_to_{$dateTo}.pdf");
        } catch (\Exception $e) {
            Log::error('Error downloading DTR report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error downloading report: ' . $e->getMessage()
            ], 500);
        }
    }
}
