<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyDtrToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token');
        $secret = config('dtr.access_token');

        if (empty($secret)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access',
            ], 401);
        }

        $currentHour = date('Y-m-d H');
        $previousHour = date('Y-m-d H', strtotime('-1 hour'));
        $prevHour = date('Y-m-d H', strtotime('+1 hour'));

        $validTokens = [
            hash('sha256', $secret . $currentHour),
            hash('sha256', $secret . $previousHour),
            hash('sha256', $secret . $prevHour),
        ];

        if (!in_array($token, $validTokens)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access',
            ], 401);
        }

        return $next($request);
    }
}
