<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TunnelController extends Controller
{
    public function existencia(Request $request)
    {
        // Validamos entrada
        $request->validate([
            'art_id' => 'required|integer',
        ]);

        // Buscamos existencia en tabla articulo
        $existencia = DB::table('articulo')
            ->where('art_id', $request->art_id)
            ->value('existencia');

        // Si no encuentra
        if ($existencia === null) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Artículo no encontrado'
            ], 404);
        }

        // Respuesta exitosa
        return response()->json([
            'ok' => true,
            'existencia' => $existencia
        ]);
    }
}
