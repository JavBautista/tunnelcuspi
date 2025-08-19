<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;

class CotizacionController extends Controller
{
    public function crear(Request $request)
    {
        try {
            // Validar estructura principal
            $validator = Validator::make($request->all(), [
                'cotizacion' => 'required|array',
                'cotizacion.fecha' => 'required|date',
                'cotizacion.header' => 'required|string',
                'cotizacion.footer' => 'required|string',
                'cotizacion.total' => 'required|numeric|min:0',
                'cotizacion.cli_id' => 'required|integer',
                'cotizacion.usu_id' => 'required|integer',
                'cotizacion.mon_id' => 'integer|min:1',
                'cotizacion.vnd_id' => 'nullable|integer',
                'cotizacion.subtotal' => 'nullable|numeric|min:0',
                'cotizacion.descuento' => 'nullable|numeric|min:0',
                'cotizacion.status' => 'integer|in:0,1',
                'cotizacion.img' => 'boolean',
                'cotizacion.caracteristicas' => 'boolean',
                'cotizacion.desglosado' => 'boolean',
                
                // Validar detalles
                'detalles' => 'required|array|min:1',
                'detalles.*.art_id' => 'required|integer',
                'detalles.*.clave' => 'required|string|max:45',
                'detalles.*.descripcion' => 'required|string|max:1000',
                'detalles.*.cantidad' => 'required|numeric|min:0.001',
                'detalles.*.unidad' => 'required|string|max:5',
                'detalles.*.precioCompra' => 'required|numeric|min:0',
                'detalles.*.precioCon' => 'required|numeric|min:0',
                'detalles.*.importeCompra' => 'required|numeric|min:0',
                'detalles.*.importeCon' => 'required|numeric|min:0',
                'detalles.*.diferencia' => 'required|numeric',
                'detalles.*.utilidad' => 'required|numeric',
                'detalles.*.orden' => 'required|integer|min:1',
                
                // Validar impuestos (opcional)
                'impuestos' => 'nullable|array',
                'impuestos.*.imp_id' => 'required_with:impuestos|integer',
                'impuestos.*.total' => 'required_with:impuestos|numeric|min:0',
                'impuestos.*.subtotal' => 'required_with:impuestos|numeric|min:0',
                'impuestos.*.tras' => 'required_with:impuestos|boolean',
                'impuestos.*.orden' => 'required_with:impuestos|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'ok' => false,
                    'mensaje' => 'Datos inválidos',
                    'errores' => $validator->errors()
                ], 400);
            }

            // Iniciar transacción
            DB::beginTransaction();

            $cotizacionData = $request->input('cotizacion');
            $detalles = $request->input('detalles');
            $impuestos = $request->input('impuestos', []);

            // 1. VALIDAR REFERENCIAS OBLIGATORIAS
            $cliente = DB::table('cliente')
                ->where('cli_id', $cotizacionData['cli_id'])
                ->where('status', 1)
                ->first();

            if (!$cliente) {
                throw new Exception("Cliente ID {$cotizacionData['cli_id']} no existe o está inactivo");
            }

            $usuario = DB::table('usuario')
                ->where('usu_id', $cotizacionData['usu_id'])
                ->where('status', 1)
                ->first();

            if (!$usuario) {
                throw new Exception("Usuario ID {$cotizacionData['usu_id']} no existe o está inactivo");
            }

            // Validar moneda (opcional, default = 1)
            $monedaId = $cotizacionData['mon_id'] ?? 1;
            $moneda = DB::table('moneda')
                ->where('mon_id', $monedaId)
                ->where('status', 1)
                ->first();

            if (!$moneda) {
                throw new Exception("Moneda ID {$monedaId} no existe o está inactiva");
            }

            // Validar vendedor (opcional)
            if (!empty($cotizacionData['vnd_id'])) {
                $vendedor = DB::table('vendedor')
                    ->where('vnd_id', $cotizacionData['vnd_id'])
                    ->where('status', 1)
                    ->first();

                if (!$vendedor) {
                    throw new Exception("Vendedor ID {$cotizacionData['vnd_id']} no existe o está inactivo");
                }
            }

            // 2. VALIDAR ARTÍCULOS EN DETALLES
            foreach ($detalles as $index => $detalle) {
                $articulo = DB::table('articulo')
                    ->where('art_id', $detalle['art_id'])
                    ->where('status', 1)
                    ->first();

                if (!$articulo) {
                    throw new Exception("Artículo ID {$detalle['art_id']} en detalle #{$index} no existe o está inactivo");
                }

                // Validar existencia suficiente
                if ($articulo->existencia < $detalle['cantidad']) {
                    throw new Exception("Artículo {$articulo->clave} no tiene existencia suficiente. Disponible: {$articulo->existencia}, Solicitado: {$detalle['cantidad']}");
                }
            }

            // 3. CALCULAR SUBTOTAL AUTOMÁTICO DESDE DETALLES
            $subtotalCalculado = 0;
            foreach ($detalles as $detalle) {
                $subtotalCalculado += ($detalle['cantidad'] * $detalle['precioCon']);
            }

            // 4. INSERTAR COTIZACIÓN PRINCIPAL CON VALORES SEGUROS
            $cotizacionInsert = [
                'fecha' => $cotizacionData['fecha'],
                'header' => $cotizacionData['header'],
                'footer' => $cotizacionData['footer'],
                'total' => $cotizacionData['total'],
                'status' => $cotizacionData['status'] ?? 1,
                'img' => $cotizacionData['img'] ?? 0,
                'caracteristicas' => $cotizacionData['caracteristicas'] ?? 0,
                'desglosado' => $cotizacionData['desglosado'] ?? 0,
                'cli_id' => $cotizacionData['cli_id'],
                'usu_id' => $cotizacionData['usu_id'],
                'mon_id' => $monedaId,
                'vnd_id' => $cotizacionData['vnd_id'] ?? null,
                // ✅ CORRECCIÓN CRÍTICA: Usar valores calculados/seguros en lugar de NULL
                'subtotal' => $cotizacionData['subtotal'] ?? $subtotalCalculado,
                'descuento' => $cotizacionData['descuento'] ?? 0.00,
                'monAbr' => $moneda->abr,
                'monTipoCambio' => $moneda->tipoCambio,
                'mosDescuento' => $cotizacionData['mosDescuento'] ?? 0,
                'mosPeso' => $cotizacionData['mosPeso'] ?? 0,
                'impuestos' => $cotizacionData['aplicarImpuestos'] ?? 0,
                'mosFirma' => $cotizacionData['mosFirma'] ?? 1,
                'leyendaImpuestos' => $cotizacionData['leyendaImpuestos'] ?? 1,
                'mosParidad' => $cotizacionData['mosParidad'] ?? 0,
                'bloqueada' => $cotizacionData['bloqueada'] ?? 0,
                'mosDetallePaq' => $cotizacionData['mosDetallePaq'] ?? 0,
                'mosClaveArt' => $cotizacionData['mosClaveArt'] ?? 1,
                'mosPreAntDesc' => $cotizacionData['mosPreAntDesc'] ?? 0,
            ];

            $cotizacionId = DB::table('cotizacion')->insertGetId($cotizacionInsert);

            if (!$cotizacionId) {
                throw new Exception('Error al insertar la cotización principal');
            }

            // 5. INSERTAR DETALLES DE ARTÍCULOS
            foreach ($detalles as $detalle) {
                $detalleInsert = [
                    'cot_id' => $cotizacionId,
                    'art_id' => $detalle['art_id'],
                    'clave' => $detalle['clave'],
                    'descripcion' => $detalle['descripcion'],
                    'cantidad' => $detalle['cantidad'],
                    'unidad' => $detalle['unidad'],
                    'precioCompra' => $detalle['precioCompra'],
                    'precioCon' => $detalle['precioCon'],
                    'importeCompra' => $detalle['importeCompra'],
                    'importeCon' => $detalle['importeCon'],
                    'diferencia' => $detalle['diferencia'],
                    'utilidad' => $detalle['utilidad'],
                    'descPorcentaje' => $detalle['descPorcentaje'] ?? 0.00,
                    'descTotal' => $detalle['descTotal'] ?? 0.00,
                    'caracteristicas' => $detalle['caracteristicas'] ?? null,
                    'orden' => $detalle['orden'],
                    // Campos opcionales de precios e importes
                    'precioNorSin' => $detalle['precioNorSin'] ?? null,
                    'precioNorCon' => $detalle['precioNorCon'] ?? null,
                    'precioSin' => $detalle['precioSin'] ?? null,
                    'importeNorSin' => $detalle['importeNorSin'] ?? null,
                    'importeNorCon' => $detalle['importeNorCon'] ?? null,
                    'importeSin' => $detalle['importeSin'] ?? null,
                    'monPrecioNorSin' => $detalle['monPrecioNorSin'] ?? null,
                    'monPrecioNorCon' => $detalle['monPrecioNorCon'] ?? null,
                    'monPrecioSin' => $detalle['monPrecioSin'] ?? null,
                    'monPrecioCon' => $detalle['monPrecioCon'] ?? null,
                    'monImporteNorSin' => $detalle['monImporteNorSin'] ?? null,
                    'monImporteNorCon' => $detalle['monImporteNorCon'] ?? null,
                    'monImporteSin' => $detalle['monImporteSin'] ?? null,
                    'monImporteCon' => $detalle['monImporteCon'] ?? null,
                ];

                $detalleResult = DB::table('detallecot')->insert($detalleInsert);
                
                if (!$detalleResult) {
                    throw new Exception("Error al insertar detalle del artículo {$detalle['clave']}");
                }
            }

            // 6. INSERTAR IMPUESTOS EN COTIZACIONIMP (si los hay)
            if (!empty($impuestos)) {
                foreach ($impuestos as $impuesto) {
                    $impuestoInsert = [
                        'cot_id' => $cotizacionId,
                        'imp_id' => $impuesto['imp_id'],
                        'total' => $impuesto['total'],
                        'subtotal' => $impuesto['subtotal'],
                        'tras' => $impuesto['tras'] ? 1 : 0,
                        'orden' => $impuesto['orden'],
                        'monTotal' => $impuesto['monTotal'] ?? null,
                        'monSubtotal' => $impuesto['monSubtotal'] ?? null,
                    ];

                    $impuestoResult = DB::table('cotizacionimp')->insert($impuestoInsert);
                    
                    if (!$impuestoResult) {
                        throw new Exception("Error al insertar impuesto ID {$impuesto['imp_id']}");
                    }
                }
            }

            // 7. INSERTAR RELACIÓN DETALLECOTIMPUESTO (CRÍTICO PARA SICAR)
            if (!empty($impuestos)) {
                foreach ($impuestos as $impuesto) {
                    // Para cada impuesto, vincularlo con todos los artículos de la cotización
                    foreach ($detalles as $detalle) {
                        $detalleImpuestoInsert = [
                            'cot_id' => $cotizacionId,
                            'art_id' => $detalle['art_id'],
                            'imp_id' => $impuesto['imp_id']
                        ];

                        $detalleImpuestoResult = DB::table('detallecotimpuesto')->insert($detalleImpuestoInsert);
                        
                        if (!$detalleImpuestoResult) {
                            throw new Exception("Error al insertar relación detalle-impuesto: Art {$detalle['art_id']} + Imp {$impuesto['imp_id']}");
                        }
                    }
                }
            }

            // 8. Confirmar transacción
            DB::commit();

            // Obtener la cotización completa para respuesta
            $cotizacionCompleta = DB::table('cotizacion as c')
                ->select(
                    'c.*',
                    'cl.nombre as cliente_nombre',
                    'cl.rfc as cliente_rfc',
                    'u.nombre as usuario_nombre',
                    'm.moneda as moneda_nombre',
                    'v.nombre as vendedor_nombre'
                )
                ->join('cliente as cl', 'c.cli_id', '=', 'cl.cli_id')
                ->join('usuario as u', 'c.usu_id', '=', 'u.usu_id')
                ->join('moneda as m', 'c.mon_id', '=', 'm.mon_id')
                ->leftJoin('vendedor as v', 'c.vnd_id', '=', 'v.vnd_id')
                ->where('c.cot_id', $cotizacionId)
                ->first();

            return response()->json([
                'ok' => true,
                'mensaje' => 'Cotización creada exitosamente',
                'cot_id' => $cotizacionId,
                'cotizacion' => $cotizacionCompleta,
                'total_detalles' => count($detalles),
                'total_impuestos' => count($impuestos)
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'ok' => false,
                'mensaje' => 'Error al crear cotización: ' . $e->getMessage(),
                'error_code' => 'COTIZACION_ERROR'
            ], 500);
        }
    }
}