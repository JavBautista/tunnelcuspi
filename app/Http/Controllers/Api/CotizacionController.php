<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CotizacionController extends Controller
{
    public function crear(Request $request)
    {
        try {
            // 1. VALIDAR DATOS (siguiendo patrón de PedidoController)
            $datos = $request->validate([
                'cli_id' => 'required|integer',
                'vnd_id' => 'nullable|integer',
                'fecha' => 'required|date',
                'header' => 'nullable|string',
                'footer' => 'nullable|string',
                'descuento' => 'nullable|numeric|min:0',
                'articulos' => 'required|array|min:1',
                'articulos.*.art_id' => 'required|integer',
                'articulos.*.cantidad' => 'required|numeric|min:0.0001',
                'articulos.*.precioCon' => 'required|numeric|min:0.000001',
                'articulos.*.precioCompra' => 'nullable|numeric|min:0',
                'opciones' => 'required|array',
                'moneda' => 'nullable|array'
            ]);

            Log::info('TUNNEL: Creando cotización en SICAR', [
                'cli_id' => $datos['cli_id'],
                'articulos_count' => count($datos['articulos'])
            ]);

            // 2. VALIDAR FOREIGN KEYS
            $cliente = DB::table('cliente')->where('cli_id', $datos['cli_id'])->where('status', 1)->first();
            if (!$cliente) {
                throw new \Exception("Cliente ID {$datos['cli_id']} no existe o está inactivo");
            }

            foreach ($datos['articulos'] as $index => $articulo) {
                $articuloDB = DB::table('articulo')->where('art_id', $articulo['art_id'])->where('status', 1)->first();
                if (!$articuloDB) {
                    throw new \Exception("Artículo ID {$articulo['art_id']} en posición {$index} no existe o está inactivo");
                }
            }

            DB::beginTransaction();

            // 3. CALCULAR TOTALES
            $totales = $this->calcularTotales($datos['articulos'], $datos['descuento'] ?? 0);

            // 4. INSERTAR COTIZACIÓN EN BD SICAR (tabla cotizacion)
            $cotizacionId = $this->insertarCotizacion($datos, $totales);

            // 5. INSERTAR DETALLES EN BD SICAR (tabla detallecot)
            $this->insertarDetalles($cotizacionId, $datos['articulos']);

            DB::commit();

            Log::info('TUNNEL: Cotización creada exitosamente', [
                'cot_id' => $cotizacionId,
                'total' => $totales['total']
            ]);

            // 6. RETORNAR RESPUESTA CON ESTRUCTURA REQUERIDA POR CUSPI
            return response()->json([
                'success' => true,
                'mensaje' => 'Cotización creada correctamente en SICAR',
                'cotizacion' => [
                    'cot_id' => $cotizacionId,
                    'total' => $totales['total'],
                    'subtotal' => $totales['subtotal'],
                    'fecha' => $datos['fecha'],
                    'cliente' => $this->getClienteNombre($datos['cli_id']),
                    'articulos_count' => count($datos['articulos'])
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('TUNNEL: Error al crear cotización', [
                'error' => $e->getMessage(),
                'cli_id' => $request->input('cli_id', 'N/A')
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    private function calcularTotales($articulos, $descuento)
    {
        $subtotal = 0;
        foreach ($articulos as $articulo) {
            $subtotal += $articulo['cantidad'] * $articulo['precioCon'];
        }
        $total = $subtotal - $descuento;
        
        return [
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'descuento' => number_format($descuento, 2, '.', ''),
            'total' => number_format($total, 2, '.', '')
        ];
    }

    private function insertarCotizacion($datos, $totales)
    {
        // VALIDAR USUARIO ANTES DE INSERTAR
        $usuario = DB::table('usuario')->where('usu_id', 1)->where('status', 1)->first();
        if (!$usuario) {
            throw new \Exception("Usuario ID 1 no existe o está inactivo");
        }

        // USAR ESTRUCTURA EXACTA DE BD SICAR (34 campos)
        $cotizacion = [
            'fecha' => $datos['fecha'],
            'header' => $datos['header'] ?? '',
            'footer' => $datos['footer'] ?? '',
            'subtotal' => $totales['subtotal'],
            'descuento' => $totales['descuento'],
            'total' => $totales['total'],
            'monSubtotal' => null,
            'monDescuento' => null,
            'monTotal' => null,
            'monAbr' => $datos['moneda']['monAbr'] ?? 'MXN',
            'monTipoCambio' => $datos['moneda']['monTipoCambio'] ?? 1.000000,
            'peso' => null,
            'status' => 1,
            // OPCIONES CON DEFAULTS CORRECTOS DE SICAR
            'img' => $datos['opciones']['img'] ?? 0,
            'caracteristicas' => $datos['opciones']['caracteristicas'] ?? 0,
            'desglosado' => $datos['opciones']['desglosado'] ?? 1,
            'mosDescuento' => $datos['opciones']['mosDescuento'] ?? 0,
            'mosPeso' => $datos['opciones']['mosPeso'] ?? 0,
            'impuestos' => $datos['opciones']['impuestos'] ?? 0,
            'mosFirma' => $datos['opciones']['mosFirma'] ?? 1,
            'leyendaImpuestos' => $datos['opciones']['leyendaImpuestos'] ?? 1,
            'mosParidad' => $datos['opciones']['mosParidad'] ?? 0,
            'bloqueada' => $datos['opciones']['bloqueada'] ?? 0,
            'mosDetallePaq' => $datos['opciones']['mosDetallePaq'] ?? 0,
            'mosClaveArt' => $datos['opciones']['mosClaveArt'] ?? 1,
            'mosPreAntDesc' => $datos['opciones']['mosPreAntDesc'] ?? 0,
            'folioMovil' => null,
            'serieMovil' => null,
            'totalSipa' => null,
            // FOREIGN KEYS
            'usu_id' => 1, // Usuario por defecto TUNNEL
            'cli_id' => $datos['cli_id'],
            'mon_id' => $datos['moneda']['mon_id'] ?? 1,
            'vnd_id' => $datos['vnd_id'] ?? null
        ];

        return DB::table('cotizacion')->insertGetId($cotizacion);
    }

    private function insertarDetalles($cotizacionId, $articulos)
    {
        foreach ($articulos as $orden => $articulo) {
            // Obtener datos del artículo
            $articuloDB = DB::table('articulo')->where('art_id', $articulo['art_id'])->first();
            
            $cantidad = floatval($articulo['cantidad']);
            $precioCon = floatval($articulo['precioCon']);
            $precioCompra = floatval($articulo['precioCompra'] ?? $articuloDB->precioCompra ?? 0);
            
            // Cálculos
            $importeCon = $cantidad * $precioCon;
            $importeCompra = $cantidad * $precioCompra;
            $diferencia = $importeCon - $importeCompra;
            $utilidad = $importeCompra > 0 ? (($diferencia / $importeCompra) * 100) : 0;

            // USAR ESTRUCTURA EXACTA DE BD SICAR (30 campos)
            // Siguiendo patrones de cotizaciones reales: precioNorSin = precioSin = precioCon
            $detalle = [
                'cot_id' => $cotizacionId,
                'art_id' => $articulo['art_id'],
                'clave' => $articuloDB->clave,
                'descripcion' => $articuloDB->descripcion,
                'cantidad' => number_format($cantidad, 3, '.', ''),
                'unidad' => $articuloDB->unidadVenta ?? 'PZA',
                'precioCompra' => number_format($precioCompra, 2, '.', ''),
                'precioNorSin' => number_format($precioCon, 2, '.', ''), // = precioCon según datos reales
                'precioNorCon' => number_format($precioCon, 2, '.', ''), // = precioCon según datos reales
                'precioSin' => number_format($precioCon, 2, '.', ''),    // = precioCon según datos reales
                'precioCon' => number_format($precioCon, 2, '.', ''),
                'importeCompra' => number_format($importeCompra, 2, '.', ''),
                'importeNorSin' => number_format($importeCon, 2, '.', ''), // = importeCon según datos reales
                'importeNorCon' => number_format($importeCon, 2, '.', ''), // = importeCon según datos reales
                'importeSin' => number_format($importeCon, 2, '.', ''),    // = importeCon según datos reales
                'importeCon' => number_format($importeCon, 2, '.', ''),
                'monPrecioNorSin' => null,
                'monPrecioNorCon' => null,
                'monPrecioSin' => null,
                'monPrecioCon' => null,
                'monImporteNorSin' => null,
                'monImporteNorCon' => null,
                'monImporteSin' => null,
                'monImporteCon' => null,
                'diferencia' => number_format($diferencia, 2, '.', ''),
                'utilidad' => number_format($utilidad, 6, '.', ''),
                'descPorcentaje' => 0.00,
                'descTotal' => 0.00,
                'caracteristicas' => null,
                'orden' => $orden + 1  // SICAR espera que empiece en 1, no en 0
            ];

            DB::table('detallecot')->insert($detalle);
        }
    }

    private function getClienteNombre($cliId)
    {
        $cliente = DB::table('cliente')->where('cli_id', $cliId)->first();
        return $cliente ? $cliente->nombre : 'Cliente desconocido';
    }
}