<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CotizacionController extends Controller
{
    /**
     * BACKUP DEL MÉTODO ORIGINAL - NO TOCAR
     * Método original que funcionaba con CUSPI antes de la fusión
     */
    public function crearOriginal(Request $request)
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

            Log::info('TUNNEL: Creando cotización en SICAR (MÉTODO ORIGINAL)', [
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

            // 4. INSERTAR COTIZACIÓN EN BD SICAR (tabla cotizacion) - MÉTODO ORIGINAL
            $cotizacionId = $this->insertarCotizacionOriginal($datos, $totales);

            // 5. INSERTAR DETALLES EN BD SICAR (tabla detallecot)
            $this->insertarDetalles($cotizacionId, $datos['articulos']);

            DB::commit();

            Log::info('TUNNEL: Cotización creada exitosamente (MÉTODO ORIGINAL)', [
                'cot_id' => $cotizacionId,
                'total' => $totales['total']
            ]);

            // 6. RETORNAR RESPUESTA CON ESTRUCTURA REQUERIDA POR CUSPI
            return response()->json([
                'success' => true,
                'mensaje' => 'Cotización creada correctamente en SICAR (método original)',
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
            
            Log::error('TUNNEL: Error al crear cotización (MÉTODO ORIGINAL)', [
                'error' => $e->getMessage(),
                'cli_id' => $request->input('cli_id', 'N/A')
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

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

    /**
     * BACKUP DEL MÉTODO ORIGINAL insertarCotizacion - NO TOCAR
     */
    private function insertarCotizacionOriginal($datos, $totales)
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

    private function insertarCotizacion($datos, $totales)
    {
        // FUSIÓN: USAR LÓGICA DEL MÉTODO QUE FUNCIONA (crearCotizacionComoSicar)
        
        // 1. OBTENER CONFIGURACIÓN DE VENTACONF (como lo hace SICAR exitosamente)
        $config = $this->obtenerConfiguracionVentaConf();
        
        // 2. OBTENER MONEDA POR DEFECTO (con campos correctos)
        $monedaDefault = $this->obtenerMonedaPorDefecto();
        
        // 3. OBTENER USUARIO (por ahora usamos ID 1)
        $usuario = $this->obtenerUsuario();

        // 4. USAR MONEDA DESDE REQUEST SI VIENE, SINO LA POR DEFECTO
        $monedaFinal = [
            'mon_id' => $datos['moneda']['mon_id'] ?? $monedaDefault['mon_id'],
            'abreviacion' => $datos['moneda']['monAbr'] ?? $monedaDefault['abreviacion'],
            'tipoCambio' => $datos['moneda']['monTipoCambio'] ?? $monedaDefault['tipoCambio']
        ];

        // 5. CREAR COTIZACIÓN CON CONSTRUCTOR COMPLETO (TODOS LOS 34 CAMPOS) - MÉTODO QUE FUNCIONA
        $cotizacion = [
            // NO incluir cot_id - es AUTO_INCREMENT
            'fecha' => $datos['fecha'],                                 // Fecha desde CUSPI
            'header' => $datos['header'] ?? $config['cotHeader'],       // Header desde CUSPI o config
            'footer' => $datos['footer'] ?? $config['cotFooter'],       // Footer desde CUSPI o config
            'subtotal' => $totales['subtotal'],                         // Calculado desde artículos
            'descuento' => $totales['descuento'] == '0.00' ? null : $totales['descuento'],  // ✅ CRÍTICO: null si es 0
            'total' => $totales['total'],                               // Calculado
            'monSubtotal' => null,                                      // null en cotizaciones normales
            'monDescuento' => null,                                     // null en cotizaciones normales
            'monTotal' => null,                                         // null en cotizaciones normales
            'monAbr' => $monedaFinal['abreviacion'],                    // Desde moneda seleccionada
            'monTipoCambio' => $monedaFinal['tipoCambio'],             // Desde moneda seleccionada
            'peso' => null,                                             // ✅ CRÍTICO: null, no 0.0000
            'status' => 1,                                              // 1 = activa
            'img' => $config['cotMosImg'],                              // desde ventaconf
            'caracteristicas' => $config['cotMosCar'],                  // desde ventaconf
            'desglosado' => $config['cotDesglosar'],                    // desde ventaconf
            'mosDescuento' => $config['cotDescuento'],                  // desde ventaconf
            'mosPeso' => $config['cotPeso'],                            // desde ventaconf
            'impuestos' => $datos['opciones']['impuestos'] ?? 0,        // Desde CUSPI o 0
            'mosFirma' => $config['cotMosFirma'],                       // desde ventaconf
            'leyendaImpuestos' => $config['cotLeyendaImpuestos'],       // desde ventaconf
            'mosParidad' => $config['cotMosParidad'],                   // desde ventaconf
            'bloqueada' => 0,                                           // 0 = no bloqueada al crear
            'mosDetallePaq' => $config['cotMosDetallePaq'],             // desde ventaconf
            'mosClaveArt' => $config['cotMosClaveArt'],                 // desde ventaconf
            'folioMovil' => null,                                       // null (no se usa)
            'serieMovil' => null,                                       // null (no se usa)
            'totalSipa' => null,                                        // null (no hay SIPA al crear)
            'mosPreAntDesc' => $config['cotMosPreAntDesc'],             // desde ventaconf
            'usu_id' => $usuario['usu_id'],                             // ID del usuario
            'cli_id' => $datos['cli_id'],                               // Cliente desde CUSPI
            'mon_id' => $monedaFinal['mon_id'],                         // ID moneda seleccionada
            'vnd_id' => $datos['vnd_id'] ?? $usuario['vnd_id']          // Vendedor desde CUSPI o usuario
        ];

        // 6. GUARDAR COTIZACIÓN EN BD (método que funciona)
        $cotizacionId = DB::table('cotizacion')->insertGetId($cotizacion);
        
        // 7. CREAR HISTORIAL (como lo hace el método exitoso)
        $this->crearHistorial($cotizacionId, $usuario);
        
        return $cotizacionId;
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
                'descPorcentaje' => 0.00,  // BD requiere NOT NULL
                'descTotal' => 0.00,      // BD requiere NOT NULL
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

    /**
     * NUEVO: Crear cotización siguiendo EXACTAMENTE el flujo de SICAR
     * Basado en /home/dev/Proyectos/dev_sicar/FLUJO_EXACTO_NUEVA_COTIZACION.md
     */
    public function crearCotizacionComoSicar()
    {
        try {
            Log::info('TUNNEL: Iniciando creación de cotización usando flujo exacto de SICAR');

            DB::beginTransaction();

            // 1. OBTENER CONFIGURACIÓN DE VENTACONF (como lo hace SICAR)
            $config = $this->obtenerConfiguracionVentaConf();
            
            // 2. OBTENER MONEDA POR DEFECTO
            $monedaDefault = $this->obtenerMonedaPorDefecto();
            
            // 3. OBTENER CLIENTE POR DEFECTO ("PÚBLICO EN GENERAL")
            $clienteDefault = $this->obtenerClientePorDefecto();
            
            // 4. OBTENER USUARIO (por ahora usamos ID 1)
            $usuario = $this->obtenerUsuario();

            // 5. CREAR COTIZACIÓN CON CONSTRUCTOR COMPLETO (TODOS LOS 34 CAMPOS)
            $cotizacion = [
                // NO incluir cot_id - es AUTO_INCREMENT
                'fecha' => date('Y-m-d'),                           // new Date()
                'header' => $config['cotHeader'],                   // desde ventaconf
                'footer' => $config['cotFooter'],                   // desde ventaconf
                'subtotal' => '0.00',                               // BigDecimal.ZERO
                'descuento' => null,                                // null en cotizaciones nuevas
                'total' => '0.00',                                  // BigDecimal.ZERO
                'monSubtotal' => null,                              // null en cotizaciones nuevas
                'monDescuento' => null,                             // null en cotizaciones nuevas
                'monTotal' => null,                                 // null en cotizaciones nuevas
                'monAbr' => $monedaDefault['abreviacion'],          // "MXN"
                'monTipoCambio' => $monedaDefault['tipoCambio'],    // 1.000000
                'peso' => null,                                     // null en cotizaciones nuevas
                'status' => 1,                                      // 1 = activa
                'img' => $config['cotMosImg'],                      // desde ventaconf
                'caracteristicas' => $config['cotMosCar'],          // desde ventaconf
                'desglosado' => $config['cotDesglosar'],            // desde ventaconf
                'mosDescuento' => $config['cotDescuento'],          // desde ventaconf
                'mosPeso' => $config['cotPeso'],                    // desde ventaconf
                'impuestos' => 0,                                   // 0 por defecto en nuevas
                'mosFirma' => $config['cotMosFirma'],               // desde ventaconf
                'leyendaImpuestos' => $config['cotLeyendaImpuestos'], // desde ventaconf
                'mosParidad' => $config['cotMosParidad'],           // desde ventaconf
                'bloqueada' => 0,                                   // 0 = no bloqueada al crear
                'mosDetallePaq' => $config['cotMosDetallePaq'],     // desde ventaconf
                'mosClaveArt' => $config['cotMosClaveArt'],         // desde ventaconf
                'folioMovil' => null,                               // null (no se usa)
                'serieMovil' => null,                               // null (no se usa)
                'totalSipa' => null,                                // null (no hay SIPA al crear)
                'mosPreAntDesc' => $config['cotMosPreAntDesc'],     // desde ventaconf
                'usu_id' => $usuario['usu_id'],                     // ID del usuario
                'cli_id' => $clienteDefault['cli_id'],              // ID cliente por defecto
                'mon_id' => $monedaDefault['mon_id'],               // ID moneda por defecto
                'vnd_id' => $usuario['vnd_id']                      // ID vendedor del usuario (puede ser null)
            ];

            // 6. GUARDAR COTIZACIÓN EN BD (EntityManager persist equivalente)
            $cotizacionId = DB::table('cotizacion')->insertGetId($cotizacion);

            // 7. CREAR HISTORIAL (como lo hace SICAR)
            $this->crearHistorial($cotizacionId, $usuario);

            DB::commit();

            Log::info('TUNNEL: Cotización creada siguiendo flujo exacto de SICAR', [
                'cot_id' => $cotizacionId,
                'flujo' => 'SICAR_EXACTO',
                'config_origen' => 'ventaconf'
            ]);

            // 8. RETORNAR RESPUESTA
            return response()->json([
                'success' => true,
                'mensaje' => 'Cotización creada siguiendo flujo exacto de SICAR',
                'cotizacion' => [
                    'cot_id' => $cotizacionId,
                    'fecha' => date('Y-m-d'),
                    'cliente' => $clienteDefault['nombre'],
                    'total' => '0.00',
                    'subtotal' => '0.00',
                    'moneda' => $monedaDefault['abreviacion'],
                    'flujo' => 'SICAR_EXACTO'
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('TUNNEL: Error al crear cotización con flujo SICAR', [
                'error' => $e->getMessage(),
                'flujo' => 'SICAR_EXACTO'
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'flujo' => 'SICAR_EXACTO'
            ], 400);
        }
    }

    public function crearCotizacionVacia()
    {
        try {
            Log::info('TUNNEL: Iniciando creación de cotización usando flujo exacto de SICAR');

            DB::beginTransaction();

            // 1. OBTENER CONFIGURACIÓN DE VENTACONF (como lo hace SICAR)
            $config = $this->obtenerConfiguracionVentaConf();
            
            // 2. OBTENER MONEDA POR DEFECTO
            $monedaDefault = $this->obtenerMonedaPorDefecto();
            
            // 3. OBTENER CLIENTE POR DEFECTO ("PÚBLICO EN GENERAL")
            $clienteDefault = $this->obtenerClientePorDefecto();
            
            // 4. OBTENER USUARIO (por ahora usamos ID 1)
            $usuario = $this->obtenerUsuario();

            // 5. CREAR COTIZACIÓN CON CONSTRUCTOR COMPLETO (TODOS LOS 34 CAMPOS)
            $cotizacion = [
                // NO incluir cot_id - es AUTO_INCREMENT
                'fecha' => date('Y-m-d'),                           // new Date()
                'header' => $config['cotHeader'],                   // desde ventaconf
                'footer' => $config['cotFooter'],                   // desde ventaconf
                'subtotal' => '0.00',                               // BigDecimal.ZERO
                'descuento' => null,                                // null en cotizaciones nuevas
                'total' => '0.00',                                  // BigDecimal.ZERO
                'monSubtotal' => null,                              // null en cotizaciones nuevas
                'monDescuento' => null,                             // null en cotizaciones nuevas
                'monTotal' => null,                                 // null en cotizaciones nuevas
                'monAbr' => $monedaDefault['abreviacion'],          // "MXN"
                'monTipoCambio' => $monedaDefault['tipoCambio'],    // 1.000000
                'peso' => null,                                     // null en cotizaciones nuevas
                'status' => 1,                                      // 1 = activa
                'img' => $config['cotMosImg'],                      // desde ventaconf
                'caracteristicas' => $config['cotMosCar'],          // desde ventaconf
                'desglosado' => $config['cotDesglosar'],            // desde ventaconf
                'mosDescuento' => $config['cotDescuento'],          // desde ventaconf
                'mosPeso' => $config['cotPeso'],                    // desde ventaconf
                'impuestos' => 0,                                   // 0 por defecto en nuevas
                'mosFirma' => $config['cotMosFirma'],               // desde ventaconf
                'leyendaImpuestos' => $config['cotLeyendaImpuestos'], // desde ventaconf
                'mosParidad' => $config['cotMosParidad'],           // desde ventaconf
                'bloqueada' => 0,                                   // 0 = no bloqueada al crear
                'mosDetallePaq' => $config['cotMosDetallePaq'],     // desde ventaconf
                'mosClaveArt' => $config['cotMosClaveArt'],         // desde ventaconf
                'folioMovil' => null,                               // null (no se usa)
                'serieMovil' => null,                               // null (no se usa)
                'totalSipa' => null,                                // null (no hay SIPA al crear)
                'mosPreAntDesc' => $config['cotMosPreAntDesc'],     // desde ventaconf
                'usu_id' => $usuario['usu_id'],                     // ID del usuario
                'cli_id' => $clienteDefault['cli_id'],              // ID cliente por defecto
                'mon_id' => $monedaDefault['mon_id'],               // ID moneda por defecto
                'vnd_id' => $usuario['vnd_id']                      // ID vendedor del usuario (puede ser null)
            ];

            // 6. GUARDAR COTIZACIÓN EN BD (EntityManager persist equivalente)
            $cotizacionId = DB::table('cotizacion')->insertGetId($cotizacion);

            // 7. CREAR HISTORIAL (como lo hace SICAR)
            $this->crearHistorial($cotizacionId, $usuario);

            DB::commit();

            Log::info('TUNNEL: Cotización creada siguiendo flujo exacto de SICAR', [
                'cot_id' => $cotizacionId,
                'flujo' => 'SICAR_EXACTO',
                'config_origen' => 'ventaconf'
            ]);

            // 8. RETORNAR RESPUESTA
            return response()->json([
                'success' => true,
                'mensaje' => 'Cotización creada siguiendo flujo exacto de SICAR',
                'cotizacion' => [
                    'cot_id' => $cotizacionId,
                    'fecha' => date('Y-m-d'),
                    'cliente' => $clienteDefault['nombre'],
                    'total' => '0.00',
                    'subtotal' => '0.00',
                    'moneda' => $monedaDefault['abreviacion'],
                    'flujo' => 'SICAR_EXACTO'
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('TUNNEL: Error al crear cotización con flujo SICAR', [
                'error' => $e->getMessage(),
                'flujo' => 'SICAR_EXACTO'
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'flujo' => 'SICAR_EXACTO'
            ], 400);
        }
    }

    /**
     * Obtener configuración completa de ventaconf (como lo hace SICAR)
     */
    private function obtenerConfiguracionVentaConf()
    {
        $config = DB::table('ventaconf')->first();
        
        if (!$config) {
            throw new \Exception("No se encontró configuración en tabla ventaconf");
        }
        
        return [
            'cotHeader' => $config->cotHeader ?? '',
            'cotFooter' => $config->cotFooter ?? '',
            'cotMosImg' => $config->cotMosImg ?? 0,
            'cotMosCar' => $config->cotMosCar ?? 0,
            'cotDesglosar' => $config->cotDesglosar ?? 0,
            'cotDescuento' => $config->cotDescuento ?? 0,
            'cotPeso' => $config->cotPeso ?? 0,
            'cotMosFirma' => $config->cotMosFirma ?? 0,
            'cotLeyendaImpuestos' => $config->cotLeyendaImpuestos ?? '',
            'cotMosParidad' => $config->cotMosParidad ?? 0,
            'cotBloquear' => $config->cotBloquear ?? 0,
            'cotMosDetallePaq' => $config->cotMosDetallePaq ?? 0,
            'cotMosClaveArt' => $config->cotMosClaveArt ?? 0,
            'cotMosPreAntDesc' => $config->cotMosPreAntDesc ?? 0
        ];
    }

    /**
     * Obtener moneda por defecto (como lo hace SICAR)
     */
    private function obtenerMonedaPorDefecto()
    {
        // Buscar moneda nacional (mn = 1) que sería la por defecto
        $moneda = DB::table('moneda')->where('status', 1)->where('mn', 1)->first();
        
        if (!$moneda) {
            // Si no hay moneda nacional, usar la primera activa
            $moneda = DB::table('moneda')->where('status', 1)->first();
        }
        
        if (!$moneda) {
            throw new \Exception("No se encontró moneda por defecto activa");
        }
        
        return [
            'mon_id' => $moneda->mon_id,
            'abreviacion' => $moneda->abr ?? 'MXN',  // Campo se llama 'abr' no 'abreviacion'
            'tipoCambio' => $moneda->tipoCambio ?? 1.000000
        ];
    }

    /**
     * Obtener cliente por defecto "PÚBLICO EN GENERAL" (como lo hace SICAR)
     */
    private function obtenerClientePorDefecto()
    {
        // Buscar cliente "PÚBLICO EN GENERAL" o similar
        $cliente = DB::table('cliente')
            ->where('status', 1)
            ->where(function($query) {
                $query->whereRaw('UPPER(nombre) LIKE ?', ['%PÚBLICO%'])
                      ->orWhereRaw('UPPER(nombre) LIKE ?', ['%PUBLICO%'])
                      ->orWhereRaw('UPPER(nombre) LIKE ?', ['%GENERAL%']);
            })
            ->first();
            
        if (!$cliente) {
            // Si no existe, usar el primer cliente activo
            $cliente = DB::table('cliente')->where('status', 1)->first();
        }
        
        if (!$cliente) {
            throw new \Exception("No se encontró cliente por defecto activo");
        }
        
        return [
            'cli_id' => $cliente->cli_id,
            'nombre' => $cliente->nombre
        ];
    }

    /**
     * Obtener usuario actual (por ahora hardcodeado a ID 1)
     */
    private function obtenerUsuario()
    {
        $usuario = DB::table('usuario')->where('usu_id', 1)->where('status', 1)->first();
        
        if (!$usuario) {
            throw new \Exception("Usuario ID 1 no existe o está inactivo");
        }
        
        // Obtener vendedor del usuario si existe
        $vendedor = null;
        if ($usuario->vnd_id) {
            $vendedor = DB::table('vendedor')->where('vnd_id', $usuario->vnd_id)->where('status', 1)->first();
        }
        
        return [
            'usu_id' => $usuario->usu_id,
            'vnd_id' => $vendedor ? $vendedor->vnd_id : null
        ];
    }

    /**
     * Crear historial (como lo hace SICAR)
     */
    private function crearHistorial($cotizacionId, $usuario)
    {
        $historial = [
            'movimiento' => 1,                                 // Tipo de movimiento (1 = creación)
            'fecha' => date('Y-m-d H:i:s'),                    // new Date()
            'tabla' => 'cotizacion',                           // Nombre de la tabla
            'id' => $cotizacionId,                             // ID del registro
            'usu_id' => $usuario['usu_id']                     // ID del usuario
        ];
        
        DB::table('historial')->insert($historial);
        
        Log::info('TUNNEL: Historial creado para cotización', [
            'cot_id' => $cotizacionId,
            'movimiento' => 'creación'
        ]);
    }
}