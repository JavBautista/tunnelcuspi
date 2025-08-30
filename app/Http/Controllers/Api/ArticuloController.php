<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Articulo;
use App\Models\Proveedor;
use App\Models\ProveedorArticulo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ArticuloController extends Controller
{
    /**
     * Asignar proveedor a un artículo - Replica comportamiento exacto de SICAR
     * Basado en: ArticuloLogic.canAddPrecioProveedor() y DPrecioProveedor.class
     */
    public function asignarProveedor(Request $request)
    {
        try {
            DB::beginTransaction();

            // 1. Validación básica de entrada
            $validator = Validator::make($request->all(), [
                'art_id' => 'required|integer',
                'pro_id' => 'required|integer', 
                'claveProveedor' => 'nullable|string|max:45',
                'precioCompra' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $art_id = $request->art_id;
            $pro_id = $request->pro_id;
            $claveProveedor = $request->claveProveedor;
            $precioCompra = $request->precioCompra;

            // 2. VALIDACIÓN FK: Verificar que existe artículo ACTIVO (como SICAR)
            $articulo = DB::table('articulo')
                ->where('art_id', $art_id)
                ->where('status', 1)  // CRÍTICO: Solo artículos activos
                ->first();

            if (!$articulo) {
                throw new \Exception("Artículo no existe o está inactivo");
            }

            // 3. VALIDACIÓN FK: Verificar que existe proveedor ACTIVO (como SICAR)
            $proveedor = DB::table('proveedor')
                ->where('pro_id', $pro_id)
                ->where('status', 1)  // CRÍTICO: Solo proveedores activos
                ->first();

            if (!$proveedor) {
                throw new \Exception("Proveedor no existe o está inactivo");
            }

            // 4. claveProveedor - Convertir null a string vacío para BD
            if (is_null($claveProveedor)) {
                $claveProveedor = '';
            }

            // 5. precioCompra >= 0 - CUSPI permite precios $0.00
            // (Cambio 2025-08-30: permitir precios en cero)

            // 6. LÓGICA ANTI-DUPLICADOS de SICAR: canAddPrecioProveedor()
            // Query exacta: SELECT 1 FROM proveedorArticulo WHERE pro_id = ? AND art_id = ? LIMIT 1
            $existe = DB::table('proveedorarticulo')
                ->where('pro_id', $pro_id)
                ->where('art_id', $art_id)
                ->count();

            $mensaje = '';
            $operacion = '';

            if ($existe > 0) {
                // UPDATE - Ya existe, actualizar (merge() en SICAR)
                DB::table('proveedorarticulo')
                    ->where('pro_id', $pro_id)
                    ->where('art_id', $art_id)
                    ->update([
                        'precioCompra' => $precioCompra,
                        'claveProveedor' => $claveProveedor,
                        'fecha' => now()
                    ]);

                $mensaje = "Relación proveedor-artículo actualizada correctamente";
                $operacion = 'UPDATE';

            } else {
                // INSERT - No existe, crear nueva (persist() en SICAR)
                DB::table('proveedorarticulo')->insert([
                    'pro_id' => $pro_id,
                    'art_id' => $art_id,
                    'claveProveedor' => $claveProveedor,
                    'precioCompra' => $precioCompra,
                    'fecha' => now()
                ]);

                $mensaje = "Relación proveedor-artículo creada correctamente";
                $operacion = 'INSERT';
            }

            DB::commit();

            // Obtener datos completos para respuesta
            $resultado = DB::table('proveedorarticulo as pa')
                ->join('articulo as a', 'pa.art_id', '=', 'a.art_id')
                ->join('proveedor as p', 'pa.pro_id', '=', 'p.pro_id')
                ->where('pa.pro_id', $pro_id)
                ->where('pa.art_id', $art_id)
                ->select(
                    'pa.*',
                    'a.clave as articulo_clave',
                    'a.descripcion as articulo_descripcion',
                    'p.nombre as proveedor_nombre',
                    'p.alias as proveedor_alias'
                )
                ->first();

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'operacion' => $operacion,
                'data' => $resultado
            ], $operacion === 'INSERT' ? 201 : 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error en asignación proveedor-artículo',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Asignación masiva de proveedor a múltiples artículos
     * NO falla si un artículo da error - procesa todos y separa éxitos/errores
     */
    public function asignarProveedorMasivo(Request $request)
    {
        try {
            DB::beginTransaction();

            // 1. Validación básica del payload
            $validator = Validator::make($request->all(), [
                'asignaciones' => 'required|array|min:1|max:1000', // Límite 1000 por lote
                'asignaciones.*.art_id' => 'required|integer',
                'asignaciones.*.pro_id' => 'required|integer',
                'asignaciones.*.claveProveedor' => 'nullable|string|max:45',
                'asignaciones.*.precioCompra' => 'required|numeric|min:0',
                'asignaciones.*.fila_excel' => 'required|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos inválidos en el payload',
                    'errors' => $validator->errors()
                ], 400);
            }

            $asignaciones = $request->asignaciones;
            $exitosos = [];
            $errores = [];
            $stats = [
                'total_procesados' => count($asignaciones),
                'exitosos' => 0,
                'errores' => 0,
                'actualizaciones' => 0,
                'inserciones' => 0
            ];

            // 2. Procesar cada asignación individualmente (NO fallar todo)
            foreach ($asignaciones as $asignacion) {
                try {
                    $resultado = $this->procesarAsignacionIndividual($asignacion);
                    
                    if ($resultado['success']) {
                        $exitosos[] = $resultado['data'];
                        $stats['exitosos']++;
                        
                        if ($resultado['operacion'] === 'INSERT') {
                            $stats['inserciones']++;
                        } else {
                            $stats['actualizaciones']++;
                        }
                    } else {
                        $errores[] = [
                            'art_id' => $asignacion['art_id'],
                            'pro_id' => $asignacion['pro_id'],
                            'fila_excel' => $asignacion['fila_excel'],
                            'error' => $resultado['error'],
                            'codigo_error' => $resultado['codigo_error'] ?? 'ERROR_GENERICO'
                        ];
                        $stats['errores']++;
                    }

                } catch (\Exception $e) {
                    // Error inesperado - agregar a errores y continuar
                    $errores[] = [
                        'art_id' => $asignacion['art_id'],
                        'pro_id' => $asignacion['pro_id'],
                        'fila_excel' => $asignacion['fila_excel'],
                        'error' => $e->getMessage(),
                        'codigo_error' => 'ERROR_INESPERADO'
                    ];
                    $stats['errores']++;
                }
            }

            DB::commit();

            // 3. Respuesta con estadísticas completas
            return response()->json([
                'success' => true,
                'message' => "Asignación masiva procesada: {$stats['exitosos']} exitosos, {$stats['errores']} errores",
                'stats' => $stats,
                'exitosos' => $exitosos,
                'errores' => $errores
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error crítico en asignación masiva',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Procesa una asignación individual (lógica reutilizada del endpoint individual)
     * Retorna array con success/error para manejo masivo
     */
    private function procesarAsignacionIndividual($asignacion)
    {
        try {
            $art_id = $asignacion['art_id'];
            $pro_id = $asignacion['pro_id'];
            $claveProveedor = $asignacion['claveProveedor'];
            $precioCompra = $asignacion['precioCompra'];
            $fila_excel = $asignacion['fila_excel'];

            // 1. VALIDACIÓN FK: Verificar que existe artículo ACTIVO
            $articulo = DB::table('articulo')
                ->where('art_id', $art_id)
                ->where('status', 1)
                ->first();

            if (!$articulo) {
                return [
                    'success' => false,
                    'error' => 'Artículo no existe o está inactivo',
                    'codigo_error' => 'ARTICULO_NO_EXISTE'
                ];
            }

            // 2. VALIDACIÓN FK: Verificar que existe proveedor ACTIVO
            $proveedor = DB::table('proveedor')
                ->where('pro_id', $pro_id)
                ->where('status', 1)
                ->first();

            if (!$proveedor) {
                return [
                    'success' => false,
                    'error' => 'Proveedor no existe o está inactivo',
                    'codigo_error' => 'PROVEEDOR_NO_EXISTE'
                ];
            }

            // 3. precioCompra >= 0 - CUSPI permite precios $0.00
            // (Cambio 2025-08-30: permitir precios en cero)

            // 4. claveProveedor - Convertir null a string vacío para BD
            if (is_null($claveProveedor)) {
                $claveProveedor = '';
            }

            // 4. LÓGICA ANTI-DUPLICADOS: verificar si ya existe
            $existe = DB::table('proveedorarticulo')
                ->where('pro_id', $pro_id)
                ->where('art_id', $art_id)
                ->count();

            $operacion = '';

            if ($existe > 0) {
                // UPDATE - Ya existe
                DB::table('proveedorarticulo')
                    ->where('pro_id', $pro_id)
                    ->where('art_id', $art_id)
                    ->update([
                        'precioCompra' => $precioCompra,
                        'claveProveedor' => $claveProveedor,
                        'fecha' => now()
                    ]);
                $operacion = 'UPDATE';

            } else {
                // INSERT - No existe
                DB::table('proveedorarticulo')->insert([
                    'pro_id' => $pro_id,
                    'art_id' => $art_id,
                    'claveProveedor' => $claveProveedor,
                    'precioCompra' => $precioCompra,
                    'fecha' => now()
                ]);
                $operacion = 'INSERT';
            }

            // 5. Obtener datos completos para respuesta
            $resultado = DB::table('proveedorarticulo as pa')
                ->join('articulo as a', 'pa.art_id', '=', 'a.art_id')
                ->join('proveedor as p', 'pa.pro_id', '=', 'p.pro_id')
                ->where('pa.pro_id', $pro_id)
                ->where('pa.art_id', $art_id)
                ->select(
                    'pa.*',
                    'a.clave as articulo_clave',
                    'a.descripcion as articulo_descripcion',
                    'p.nombre as proveedor_nombre',
                    'p.alias as proveedor_alias'
                )
                ->first();

            // Agregar información del lote
            $resultadoCompleto = (array) $resultado;
            $resultadoCompleto['operacion'] = $operacion;
            $resultadoCompleto['fila_excel'] = $fila_excel;

            return [
                'success' => true,
                'operacion' => $operacion,
                'data' => $resultadoCompleto
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'codigo_error' => 'ERROR_SICAR'
            ];
        }
    }
}
