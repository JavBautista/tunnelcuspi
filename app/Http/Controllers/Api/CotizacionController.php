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
            // 1. VALIDAR DATOS (menos opciones porque las obtendremos de SICAR)
            $datos = $request->validate([
                'cli_id' => 'required|integer',
                'vnd_id' => 'nullable|integer',
                'fecha' => 'required|date',
                'header' => 'nullable|string',                      // Opcional - usar config si no viene
                'footer' => 'nullable|string',                      // Opcional - usar config si no viene
                'descuento' => 'nullable|numeric|min:0',
                'articulos' => 'required|array|min:1',
                'articulos.*.art_id' => 'required|integer',
                'articulos.*.cantidad' => 'required|numeric|min:0.0001',
                'articulos.*.precioCon' => 'required|numeric|min:0.000001',
                'articulos.*.precioCompra' => 'nullable|numeric|min:0',
                'opciones' => 'nullable|array',                    // Opcional - solo para casos específicos
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

        // OBTENER CONFIGURACIÓN REAL DE SICAR
        $configSicar = $this->obtenerConfiguracionSicar();
        
        // USAR CONFIGURACIÓN DE SICAR EN LUGAR DE HARDCODEAR
        $cotizacion = [
            'fecha' => $datos['fecha'],
            'header' => $datos['header'] ?? $configSicar['header'],        // Usar config SICAR
            'footer' => $datos['footer'] ?? $configSicar['footer'],        // Usar config SICAR
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
            
            // USAR CONFIGURACIÓN REAL DE SICAR (NO HARDCODEAR)
            'img' => $configSicar['img'],                                  // Desde ventaconf.cotMosImg
            'caracteristicas' => $configSicar['caracteristicas'],          // Desde ventaconf.cotMosCar
            'desglosado' => $configSicar['desglosado'],                    // Desde ventaconf.cotDesglosar
            'mosDescuento' => $configSicar['mosDescuento'],                // Desde ventaconf.cotDescuento
            'mosPeso' => $configSicar['mosPeso'],                          // Desde ventaconf.cotPeso
            'impuestos' => $datos['opciones']['impuestos'] ?? 0,           // Este puede ser específico
            'mosFirma' => $configSicar['mosFirma'],                        // Desde ventaconf.cotMosFirma
            'leyendaImpuestos' => $configSicar['leyendaImpuestos'],        // Desde ventaconf.cotLeyendaImpuestos
            'mosParidad' => $configSicar['mosParidad'],                    // Desde ventaconf.cotMosParidad
            'bloqueada' => $configSicar['bloqueada'],                      // Desde ventaconf.cotBloquear
            'mosDetallePaq' => $configSicar['mosDetallePaq'],              // Desde ventaconf.cotMosDetallePaq
            'mosClaveArt' => $configSicar['mosClaveArt'],                  // Desde ventaconf.cotMosClaveArt
            'mosPreAntDesc' => $configSicar['mosPreAntDesc'],              // Desde ventaconf.cotMosPreAntDesc
            
            'folioMovil' => null,
            'serieMovil' => null,
            'totalSipa' => null,
            'usu_id' => 1,
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

    private function obtenerConfiguracionSicar() 
    {
        // Obtener configuración de ventas/cotizaciones de SICAR
        $config = DB::table('ventaconf')->first();
        
        if (!$config) {
            throw new \Exception("No se encontró configuración de SICAR en tabla ventaconf");
        }
        
        return [
            'img' => $config->cotMosImg,
            'caracteristicas' => $config->cotMosCar,
            'desglosado' => $config->cotDesglosar,
            'mosDescuento' => $config->cotDescuento,
            'mosPeso' => $config->cotPeso,
            'impuestos' => 0, // Este parece ser específico por cotización
            'mosFirma' => $config->cotMosFirma,
            'leyendaImpuestos' => $config->cotLeyendaImpuestos,
            'mosParidad' => $config->cotMosParidad,
            'bloqueada' => $config->cotBloquear,
            'mosDetallePaq' => $config->cotMosDetallePaq,
            'mosClaveArt' => $config->cotMosClaveArt,
            'mosPreAntDesc' => $config->cotMosPreAntDesc ?? 0,
            'header' => $config->cotHeader ?? '',
            'footer' => $config->cotFooter ?? ''
        ];
    }

    private function getClienteNombre($cliId)
    {
        $cliente = DB::table('cliente')->where('cli_id', $cliId)->first();
        return $cliente ? $cliente->nombre : 'Cliente desconocido';
    }
}