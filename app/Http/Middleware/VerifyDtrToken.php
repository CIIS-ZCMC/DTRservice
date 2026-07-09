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
        $expectedToken = config('dtr.access_token');

        if (empty($expectedToken) || $token !== $expectedToken) {
         
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access',
            ], 401);
        }

        return $next($request);
    }
}
