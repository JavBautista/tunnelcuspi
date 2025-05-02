<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TunnelController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::middleware('validate.apikey')->group(function() {
    Route::post('/existencia', [TunnelController::class, 'existencia']);
});

Route::middleware('validate.apikey')->get('/sync/articulos', function (Request $request) {
    $articulos = DB::table('articulo')
        ->select('art_id', 'clave', 'precio1', 'precio2', 'existencia', 'status')
        ->orderBy('art_id', 'desc')
        ->limit(100)
        ->get();

    return response()->json([
        'ok' => true,
        'articulos' => $articulos,
    ]);
});