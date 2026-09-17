<?php

namespace App\Http\Controllers;

use App\Models\Biometrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiometricsController extends Controller
{
    /**
     * Check the biometrics table for identical/duplicate fingerprint templates.
     *
     * Supports:
     * - Single PIN inspection: GET /api/biometrics/{pin}/duplicates OR GET /api/biometrics/check-duplicates?pin=2479
     * - Full database audit:   GET /api/biometrics/check-duplicates (without pin, or with ?all=1)
     *
     * @param Request $request
     * @param int|null $pin
     * @return JsonResponse
     */
    public function checkDuplicates(Request $request, ?int $pin = null): JsonResponse
    {
        // Allow pin from route parameter or query string
        $targetPin = $pin ?? $request->query('pin');

        if ($targetPin !== null) {
            if (!is_numeric($targetPin)) {
                return response()->json([
                    'success' => false,
                    'error' => 'The provided PIN must be a valid numeric employee biometric ID.',
                ], 422);
            }

            $result = Biometrics::findDuplicateTemplates((int)$targetPin);

            if (!($result['success'] ?? false)) {
                return response()->json($result, 404);
            }

            return response()->json($result, 200);
        }

        // Global scan across the entire database
        $groups = Biometrics::findAllDuplicateTemplates();

        return response()->json([
            'success' => true,
            'message' => count($groups) > 0 
                ? 'Duplicate biometric fingerprint templates found in the database.' 
                : 'No duplicate fingerprint templates found across all enrolled employees.',
            'total_duplicate_clusters' => count($groups),
            'clusters' => $groups,
        ], 200);
    }
}
