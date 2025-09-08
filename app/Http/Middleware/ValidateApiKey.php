<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('API-KEY');

        // Si el header viene escapado (especialmente en POST), des-escaparlo
        if ($apiKey) {
            $apiKey = stripslashes($apiKey);
        }

        if (!$apiKey || $apiKey !== config('apikey.key')) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Unauthorized'
            ], 401);
        }

        return $next($request);
    }
}
