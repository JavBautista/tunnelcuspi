<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TestApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Permitir peticiones OPTIONS (preflight) sin validación
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }
        
        $apiKey = $request->header('API-KEY');
        
        // Si el header viene escapado (especialmente en POST), des-escaparlo
        if ($apiKey) {
            $apiKey = stripslashes($apiKey);
        }
        

        if (!$apiKey || $apiKey !== config('apikey.key')) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Unauthorized from TestApiKey',
                'debug' => [
                    'received' => $apiKey,
                    'headers' => $request->headers->all()
                ]
            ], 401);
        }

        return $next($request);
    }
}
