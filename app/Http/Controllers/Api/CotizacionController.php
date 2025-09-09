<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CotizacionController extends Controller
{
    /**
     * ✅ CREAR COTIZACIÓN VACÍA - MÉTODO QUE FUNCIONA
     * Crea cotización vacía siguiendo flujo exacto de SICAR
     * Estado: ✅ FUNCIONANDO - SICAR puede abrir sin problemas
     */
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
     * ✅ CREAR COTIZACIÓN + ARTÍCULO DE PRUEBA - MÉTODO QUE FUNCIONA  
     * Crea cotización + agrega artículo siguiendo flujo exacto SICAR
     * Estado: ✅ FUNCIONANDO - Basado en análisis exhaustivo SICAR
     */
    public function crearCotizacionConArticuloPrueba()
    {
        try {
            Log::info('TUNNEL: Iniciando creación de cotización + artículo siguiendo flujo exacto SICAR');

            DB::beginTransaction();

            // PASO 1: CREAR COTIZACIÓN VACÍA (usar método existente)
            $responseCotizacion = $this->crearCotizacionVacia();
            $dataCotizacion = json_decode($responseCotizacion->getContent(), true);
            
            if (!$dataCotizacion['success']) {
                throw new \Exception('Error al crear cotización base');
            }

            $cotizacionId = $dataCotizacion['cotizacion']['cot_id'];

            // PASO 2: AGREGAR ARTÍCULO DE PRUEBA
            $articuloId = 1634; // "4-1025617" - Papelera Basurero Elite 121 Lts Rojo
            $cantidad = 1.000;

            $resultado = $this->agregarArticuloInterno($cotizacionId, $articuloId, $cantidad);

            DB::commit();

            Log::info('TUNNEL: Cotización + Artículo creados exitosamente', [
                'cot_id' => $cotizacionId,
                'art_id' => $articuloId,
                'cantidad' => $cantidad,
                'flujo' => 'SICAR_EXACTO'
            ]);

            return response()->json([
                'success' => true,
                'mensaje' => 'Cotización creada y artículo agregado siguiendo flujo exacto SICAR',
                'datos' => [
                    'cotizacion' => $dataCotizacion['cotizacion'],
                    'articulo_agregado' => $resultado
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('TUNNEL: Error al crear cotización + artículo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error al crear cotización + artículo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ AGREGAR ARTÍCULO A COTIZACIÓN - MÉTODO QUE FUNCIONA
     * Agrega artículo a cotización existente siguiendo flujo exacto SICAR
     * Estado: ✅ FUNCIONANDO - Basado en análisis decompilado SICAR
     */
    public function agregarArticuloACotizacion(Request $request)
    {
        try {
            // 1. VALIDAR DATOS
            $datos = $request->validate([
                'cot_id' => 'required|integer',
                'art_id' => 'required|integer',
                'cantidad' => 'required|numeric|min:0.001'
            ]);

            DB::beginTransaction();

            // 2. VALIDAR COTIZACIÓN EXISTE Y ESTÁ ACTIVA
            $cotizacion = DB::table('cotizacion')
                ->where('cot_id', $datos['cot_id'])
                ->where('status', 1)
                ->first();
            
            if (!$cotizacion) {
                throw new \Exception("Cotización ID {$datos['cot_id']} no existe o está inactiva");
            }

            // 3. VALIDAR ARTÍCULO EXISTE Y ESTÁ ACTIVO
            $articulo = DB::table('articulo')
                ->where('art_id', $datos['art_id'])
                ->where('status', 1)
                ->first();
                
            if (!$articulo) {
                throw new \Exception("Artículo ID {$datos['art_id']} no existe o está inactivo");
            }

            // 4. VALIDAR QUE ARTÍCULO NO ESTÉ YA EN LA COTIZACIÓN (PRIMARY KEY)
            $detalleExiste = DB::table('detallecot')
                ->where('cot_id', $datos['cot_id'])
                ->where('art_id', $datos['art_id'])
                ->first();
                
            if ($detalleExiste) {
                throw new \Exception("El artículo ya está en la cotización");
            }

            // 5. CALCULAR IMPORTES SIGUIENDO ANÁLISIS EXHAUSTIVO DE SICAR
            $cantidad = floatval($datos['cantidad']);
            
            // OBTENER DATOS PARA CÁLCULOS (siguiendo análisis SICAR)
            $ventaConf = DB::table('ventaconf')->first();
            $cliente = DB::table('cliente')->where('cli_id', $cotizacion->cli_id)->first();
            
            // CÁLCULO 1: Precio de Compra (CotizacionLogic.crearDetalle línea 749)
            $precioCompraProm = $articulo->preCompraProm / ($articulo->factor ?: 1);
            $precioCompraFinal = $this->calcularPrecioConImpuestosSimple($precioCompraProm, $articulo);
            
            // CÁLCULO 2: Selección de Precio según Cliente (análisis líneas 142-185)
            $precioCon = null;
            $precioSin = null;
            
            if ($ventaConf->numPreCli ?? false) {
                // Usar precio según nivel del cliente
                $nivelPrecio = $cliente->precio ?? 1;
                switch ($nivelPrecio) {
                    case 1: $precioCon = $articulo->precio1; break;
                    case 2: $precioCon = $articulo->precio2; break;
                    case 3: $precioCon = $articulo->precio3; break;
                    case 4: $precioCon = $articulo->precio4; break;
                    default: $precioCon = $articulo->precio1; break;
                }
            } else {
                // Usar precio 1 general
                $precioCon = $articulo->precio1;
            }
            
            $precioSin = $this->calcularPrecioSinImpuestosSimple($precioCon, $articulo);
            
            // CÁLCULO 3: Importes
            $importeCompra = $precioCompraFinal * $cantidad;
            $importeSin = $precioSin * $cantidad;
            $importeCon = $precioCon * $cantidad;
            $diferencia = $importeCon - $importeCompra;
            $utilidad = $diferencia > 0 ? (($diferencia / $importeCompra) * 100) : 0;

            // 6. OBTENER PRÓXIMO ORDEN
            $maxOrden = DB::table('detallecot')
                ->where('cot_id', $datos['cot_id'])
                ->max('orden') ?? 0;
            $orden = $maxOrden + 1;

            // 7. CREAR DETALLE SIGUIENDO ANÁLISIS EXHAUSTIVO (30 CAMPOS EXACTOS)
            $detalle = [
                'cot_id' => $datos['cot_id'],
                'art_id' => $datos['art_id'],
                'clave' => $articulo->clave,
                'descripcion' => $articulo->descripcion,
                'cantidad' => number_format($cantidad, 3, '.', ''),
                'unidad' => $articulo->unidadVenta ?? 'PZA',
                'precioCompra' => number_format($precioCompraFinal, 2, '.', ''),
                'precioNorSin' => number_format($precioSin, 2, '.', ''),    // Precio normal sin impuestos
                'precioNorCon' => number_format($precioCon, 2, '.', ''),    // Precio normal con impuestos
                'precioSin' => number_format($precioSin, 2, '.', ''),       // Precio usado sin impuestos
                'precioCon' => number_format($precioCon, 2, '.', ''),       // Precio usado con impuestos
                'importeCompra' => number_format($importeCompra, 2, '.', ''),
                'importeNorSin' => number_format($importeSin, 2, '.', ''),  // Importe normal sin impuestos
                'importeNorCon' => number_format($importeCon, 2, '.', ''),  // Importe normal con impuestos
                'importeSin' => number_format($importeSin, 2, '.', ''),     // Importe sin impuestos
                'importeCon' => number_format($importeCon, 2, '.', ''),     // Importe con impuestos
                'monPrecioNorSin' => null,                                 // Campos de moneda null por defecto
                'monPrecioNorCon' => null,
                'monPrecioSin' => null,
                'monPrecioCon' => null,
                'monImporteNorSin' => null,
                'monImporteNorCon' => null,
                'monImporteSin' => null,
                'monImporteCon' => null,
                'diferencia' => number_format($diferencia, 2, '.', ''),    // Diferencia calculada
                'utilidad' => number_format($utilidad, 6, '.', ''),        // Utilidad calculada
                'descPorcentaje' => '0.00',                                // Default NOT NULL
                'descTotal' => '0.00',                                     // Default NOT NULL
                'caracteristicas' => $articulo->caracteristicas,          // Características del artículo
                'orden' => $orden
            ];

            // 8. INSERTAR DETALLE
            DB::table('detallecot')->insert($detalle);

            // 9. ACTUALIZAR TOTALES DE COTIZACIÓN (como lo hace SICAR)
            $this->actualizarTotalesCotizacion($datos['cot_id']);

            DB::commit();

            Log::info('TUNNEL: Artículo agregado a cotización siguiendo flujo SICAR', [
                'cot_id' => $datos['cot_id'],
                'art_id' => $datos['art_id'],
                'clave' => $articulo->clave,
                'cantidad' => $cantidad,
                'precio' => $precioCon,
                'importe' => $importeCon,
                'orden' => $orden
            ]);

            return response()->json([
                'success' => true,
                'mensaje' => 'Artículo agregado correctamente siguiendo flujo SICAR',
                'detalle' => [
                    'cot_id' => $datos['cot_id'],
                    'art_id' => $datos['art_id'],
                    'clave' => $articulo->clave,
                    'descripcion' => $articulo->descripcion,
                    'cantidad' => $cantidad,
                    'precio' => $precioCon,
                    'importe' => $importeCon,
                    'utilidad' => $utilidad,
                    'orden' => $orden
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('TUNNEL: Error al agregar artículo a cotización', [
                'error' => $e->getMessage(),
                'cot_id' => $request->input('cot_id', 'N/A'),
                'art_id' => $request->input('art_id', 'N/A')
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    // ========================================================================
    // MÉTODOS AUXILIARES PRIVADOS - MANTENER TODOS
    // ========================================================================

    /**
     * Método auxiliar interno para agregar artículo (usado por métodos de prueba)
     */
    private function agregarArticuloInterno($cotizacionId, $articuloId, $cantidad = 1.000)
    {
        // Simular Request para reutilizar el método público existente
        $requestData = [
            'cot_id' => $cotizacionId,
            'art_id' => $articuloId,
            'cantidad' => $cantidad
        ];
        
        $request = new \Illuminate\Http\Request();
        $request->merge($requestData);
        
        // Llamar al método público existente pero capturar solo los datos
        $response = $this->agregarArticuloACotizacion($request);
        $responseData = json_decode($response->getContent(), true);
        
        if (!$responseData['success']) {
            throw new \Exception($responseData['error'] ?? 'Error al agregar artículo');
        }
        
        return $responseData['detalle'];
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

    /**
     * Actualizar totales de cotización (como lo hace SICAR)
     */
    private function actualizarTotalesCotizacion($cotizacionId)
    {
        // Calcular totales desde los detalles (como lo hace SICAR)
        $totales = DB::table('detallecot')
            ->where('cot_id', $cotizacionId)
            ->selectRaw('
                SUM(importeCon) as subtotal,
                SUM(importeCon) as total
            ')
            ->first();

        // Actualizar cotización
        DB::table('cotizacion')
            ->where('cot_id', $cotizacionId)
            ->update([
                'subtotal' => number_format($totales->subtotal ?? 0, 2, '.', ''),
                'total' => number_format($totales->total ?? 0, 2, '.', '')
            ]);
        
        Log::info('TUNNEL: Totales de cotización actualizados', [
            'cot_id' => $cotizacionId,
            'subtotal' => $totales->subtotal ?? 0,
            'total' => $totales->total ?? 0
        ]);
    }

    /**
     * Calcula precio CON impuestos - Versión simplificada basada en análisis SICAR
     */
    private function calcularPrecioConImpuestosSimple($precioBase, $articulo)
    {
        // IEPS si aplica
        if ($articulo->iepsActivo && $articulo->cuotaIeps > 0) {
            $precioBase += $articulo->cuotaIeps;
        }

        // Obtener impuestos del artículo
        $impuestos = DB::table('articuloimpuesto')
            ->join('impuesto', 'articuloimpuesto.imp_id', '=', 'impuesto.imp_id')
            ->where('articuloimpuesto.art_id', $articulo->art_id)
            ->where('impuesto.status', 1)
            ->get();

        $precioConImpuestos = $precioBase;
        
        foreach ($impuestos as $impuesto) {
            if ($impuesto->aplicacion == 1) { // Porcentaje
                $precioConImpuestos += ($precioBase * $impuesto->porcentaje / 100);
            } else { // Cantidad fija
                $precioConImpuestos += $impuesto->porcentaje;
            }
        }

        return $precioConImpuestos;
    }

    /**
     * Calcula precio SIN impuestos - Versión simplificada
     */
    private function calcularPrecioSinImpuestosSimple($precioConImpuestos, $articulo)
    {
        $impuestos = DB::table('articuloimpuesto')
            ->join('impuesto', 'articuloimpuesto.imp_id', '=', 'impuesto.imp_id')
            ->where('articuloimpuesto.art_id', $articulo->art_id)
            ->where('impuesto.status', 1)
            ->get();

        $factorImpuestos = 1;
        
        foreach ($impuestos as $impuesto) {
            if ($impuesto->aplicacion == 1) { // Porcentaje
                $factorImpuestos += ($impuesto->porcentaje / 100);
            }
        }

        return $factorImpuestos > 1 ? ($precioConImpuestos / $factorImpuestos) : $precioConImpuestos;
    }
}