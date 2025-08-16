<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TunnelController;
use App\Http\Controllers\Api\CotizacionController;

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
    Route::post('/cotizacion/crear', [CotizacionController::class, 'crear']);
});

Route::middleware('validate.apikey')->get('/sync/departamentos', function (Request $request) {
    $departamentos = DB::table('departamento')
        ->select(
            'dep_id',
            'nombre',
            'restringido',
            'porcentaje',
            'system',
            'status',
            'comision'
        )
        ->orderBy('dep_id', 'asc')
        ->get();

    return response()->json([
        'ok' => true,
        'departamentos' => $departamentos,
    ]);
});

Route::middleware('validate.apikey')->get('/sync/categorias', function (Request $request) {
    $categorias = DB::table('categoria')
        ->select(
            'cat_id',
            'nombre',
            'system',
            'status',
            'dep_id',
            'comision'
        )
        ->orderBy('cat_id', 'asc')
        ->get();

    return response()->json([
        'ok' => true,
        'categorias' => $categorias,
    ]);
});



Route::middleware('validate.apikey')->get('/sync/articulos', function (Request $request) {
    $limit = $request->query('limit', 100);
    $offset = $request->query('offset', 0);

    $articulos = DB::table('articulo')
        ->select(
            'art_id',
            'clave',
            'claveAlterna',
            'descripcion',
            'servicio',
            'localizacion',
            'caracteristicas',
            'margen1',
            'margen2',
            'margen3',
            'margen4',
            'precio1',
            'precio2',
            'precio3',
            'precio4',
            'mayoreo1',
            'mayoreo2',
            'mayoreo3',
            'mayoreo4',
            'invMin',
            'invMax',
            'existencia',
            'status',
            'factor',
            'precioCompra',
            'preCompraProm',
            'unidadCompra',
            'unidadVenta',
            'cuentaPredial',
            'cat_id'
        )
        ->orderBy('art_id', 'asc')
        ->limit($limit)
        ->offset($offset)
        ->get();

    foreach ($articulos as $art) {
        $existenciaBodega = DB::connection('bodega')
            ->table('articulo')
            ->where('clave', $art->clave)
            ->value('existencia') ?? 0;

        $art->existencia_bodega = floatval($existenciaBodega);
    }
    
    return response()->json([
        'ok' => true,
        'articulos' => $articulos,
    ]);
});