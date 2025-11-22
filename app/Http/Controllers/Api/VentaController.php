<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * ==================================================================================
 * CONTROLADOR DE VENTAS PARA SICAR
 * ==================================================================================
 *
 * Este controlador replica EXACTAMENTE el comportamiento del módulo de ventas
 * de SICAR para que las ventas creadas desde CUSPI puedan abrirse en SICAR
 * sin problemas.
 *
 * BASADO EN:
 * - Análisis de SICAR: /home/dev/Proyectos/dev_sicar/docs/01_MODULOS/VENTAS/ANALISIS_MODULO_VENTAS_SICAR.md
 * - Especificación CUSPI: /var/www/tunnelcuspi/xdev/venta/DOCS/ESPECIFICACION.json
 * - Patrón base: PedidoController (simple y directo)
 *
 * OBJETIVO: Que SICAR pueda abrir las ventas sin problemas (como cotizaciones)
 *
 * FLUJO:
 * 1. INSERT INTO venta → obtener ven_id
 * 2. Generar folio en serieventa
 * 3. INSERT INTO detallev (artículos)
 * 4. INSERT INTO detallevimpuesto (impuestos por artículo)
 * 5. INSERT INTO ventaimp (impuestos generales)
 * 6. INSERT INTO ventatipopago (formas de pago)
 * 7. UPDATE existencia (descuenta inventario)
 * 8. INSERT INTO movimientoinventario (registro movimientos)
 * 9. INSERT INTO creditocliente (solo si es crédito)
 * 10. INSERT INTO ventanotacredito (solo si aplica)
 *
 * @author TunnelCUSPI Development Team
 * @version 1.0
 */
class VentaController extends Controller
{
    /**
     * Convertir número decimal a texto en español mexicano
     *
     * Formato: "(DOSCIENTOS CUARENTA Y DOS PESOS 44/100 MN)"
     *
     * @param float $numero
     * @return string
     */
    private function convertirTotalALetras(float $numero): string
    {
        $entero = floor($numero);
        $decimales = round(($numero - $entero) * 100);

        // Arrays de conversión
        $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $especiales = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
        $decenas = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

        if ($entero == 0) {
            $letras = 'CERO';
        } else {
            $letras = $this->convertirGrupo($entero, $unidades, $especiales, $decenas, $centenas);
        }

        // Pluralizar PESOS
        $moneda = ($entero == 1) ? 'PESO' : 'PESOS';

        return sprintf('(%s %s %02d/100 MN)', $letras, $moneda, $decimales);
    }

    /**
     * Convertir grupo de hasta 999 a letras
     */
    private function convertirGrupo(int $numero, array $unidades, array $especiales, array $decenas, array $centenas): string
    {
        if ($numero >= 1000000) {
            $millones = floor($numero / 1000000);
            $resto = $numero % 1000000;

            if ($millones == 1) {
                $texto = 'UN MILLÓN';
            } else {
                $texto = $this->convertirGrupo($millones, $unidades, $especiales, $decenas, $centenas) . ' MILLONES';
            }

            if ($resto > 0) {
                $texto .= ' ' . $this->convertirGrupo($resto, $unidades, $especiales, $decenas, $centenas);
            }

            return $texto;
        }

        if ($numero >= 1000) {
            $miles = floor($numero / 1000);
            $resto = $numero % 1000;

            if ($miles == 1) {
                $texto = 'MIL';
            } else {
                $texto = $this->convertirGrupo($miles, $unidades, $especiales, $decenas, $centenas) . ' MIL';
            }

            if ($resto > 0) {
                $texto .= ' ' . $this->convertirGrupo($resto, $unidades, $especiales, $decenas, $centenas);
            }

            return $texto;
        }

        if ($numero >= 100) {
            $centena = floor($numero / 100);
            $resto = $numero % 100;

            if ($numero == 100) {
                return 'CIEN';
            }

            $texto = $centenas[$centena];

            if ($resto > 0) {
                $texto .= ' ' . $this->convertirGrupo($resto, $unidades, $especiales, $decenas, $centenas);
            }

            return $texto;
        }

        if ($numero >= 20) {
            $decena = floor($numero / 10);
            $unidad = $numero % 10;

            $texto = $decenas[$decena];

            if ($unidad > 0) {
                $texto .= ' Y ' . $unidades[$unidad];
            }

            return $texto;
        }

        if ($numero >= 10) {
            return $especiales[$numero - 10];
        }

        return $unidades[$numero];
    }

    /**
     * Crear venta en SICAR desde CUSPI
     *
     * Endpoint: POST /api/sicar/ventas/store
     *
     * Recibe estructura completa de venta desde CUSPI e inserta en SICAR
     * siguiendo exactamente los 10 pasos del módulo de ventas.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            Log::info('TUNNEL VENTAS: Iniciando creación de venta en SICAR');

            // Iniciar transacción - SI CUALQUIER PASO FALLA, ROLLBACK COMPLETO
            DB::beginTransaction();

            // ======================================================================
            // VALIDACIONES PREVIAS
            // ======================================================================
            $validator = Validator::make($request->all(), [
                // Venta principal
                'venta' => 'required|array',
                'venta.fecha' => 'required|date_format:Y-m-d H:i:s',
                'venta.subtotal' => 'required|numeric|min:0',
                'venta.descuento' => 'nullable|numeric|min:0',
                'venta.total' => 'required|numeric|min:0',
                'venta.cli_id' => 'required|integer',
                'venta.usu_id' => 'required|integer',
                'venta.suc_id' => 'required|integer',
                'venta.status' => 'required|integer|in:1,-1',

                // Detalles (artículos) - mínimo 1
                'detalles' => 'required|array|min:1',
                'detalles.*.art_id' => 'required|integer',
                'detalles.*.clave' => 'required|string',
                'detalles.*.descripcion' => 'required|string',
                'detalles.*.cantidad' => 'required|numeric|min:0.0001',
                'detalles.*.unidad' => 'required|string',
                'detalles.*.precioSin' => 'required|numeric|min:0',
                'detalles.*.precioCon' => 'required|numeric|min:0',
                'detalles.*.importeSin' => 'required|numeric|min:0',
                'detalles.*.importeCon' => 'required|numeric|min:0',
                'detalles.*.precioCompra' => 'required|numeric|min:0',
                'detalles.*.orden' => 'required|integer',

                // Impuestos por artículo (opcional)
                'detallesImpuestos' => 'nullable|array',
                'detallesImpuestos.*.art_id' => 'required|integer',
                'detallesImpuestos.*.imp_id' => 'required|integer',
                'detallesImpuestos.*.base' => 'required|numeric|min:0',
                'detallesImpuestos.*.tasa' => 'required|numeric|min:0',
                'detallesImpuestos.*.importe' => 'required|numeric|min:0',

                // Impuestos generales (opcional)
                'impuestos' => 'nullable|array',
                'impuestos.*.imp_id' => 'required|integer',
                'impuestos.*.base' => 'required|numeric|min:0',
                'impuestos.*.importe' => 'required|numeric|min:0',

                // Formas de pago - mínimo 1
                'formasPago' => 'required|array|min:1',
                'formasPago.*.tpa_id' => 'required|integer',
                'formasPago.*.importe' => 'required|numeric|min:0',

                // Crédito (opcional)
                'creditoCliente' => 'nullable|array',

                // Notas de crédito (opcional)
                'notasCredito' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                throw new \Exception('Datos inválidos: ' . implode(', ', $validator->errors()->all()));
            }

            $datos = $validator->validated();

            // Validar que el cliente existe
            $cliente = DB::table('cliente')
                ->where('cli_id', $datos['venta']['cli_id'])
                ->where('status', 1)
                ->first();

            if (!$cliente) {
                throw new \Exception("Cliente ID {$datos['venta']['cli_id']} no existe o está inactivo");
            }

            // Validar que todos los artículos existen
            foreach ($datos['detalles'] as $detalle) {
                $articulo = DB::table('articulo')
                    ->where('art_id', $detalle['art_id'])
                    ->where('status', 1)
                    ->first();

                if (!$articulo) {
                    throw new \Exception("Artículo ID {$detalle['art_id']} no existe o está inactivo");
                }
            }

            // Obtener configuración de ventaconf
            $ventaConf = DB::table('ventaconf')->first();

            // ======================================================================
            // CALCULAR CAMPOS DE COSTO Y UTILIDAD (antes del INSERT)
            // ======================================================================
            Log::info('TUNNEL VENTAS: Calculando campos de costo y utilidad');

            $totalCompra = 0;
            $subtotalCompra = 0;

            foreach ($datos['detalles'] as $detalle) {
                $costoArticulo = $detalle['precioCompra'] * $detalle['cantidad'];
                $totalCompra += $costoArticulo;
                $subtotalCompra += $costoArticulo;
            }

            $totalUtilidad = $datos['venta']['total'] - $totalCompra;
            $subtotalUtilidad = $datos['venta']['subtotal'] - $subtotalCompra;

            Log::info('TUNNEL VENTAS: Campos calculados', [
                'totalCompra' => $totalCompra,
                'totalUtilidad' => $totalUtilidad,
                'subtotalCompra' => $subtotalCompra,
                'subtotalUtilidad' => $subtotalUtilidad
            ]);

            // ======================================================================
            // GENERAR LETRA (total en letras)
            // ======================================================================
            $letra = $this->convertirTotalALetras($datos['venta']['total']);

            Log::info('TUNNEL VENTAS: Letra generada', ['letra' => $letra]);

            // ======================================================================
            // PASO 1: INSERT INTO venta
            // ======================================================================
            Log::info('TUNNEL VENTAS: Paso 1 - Insertando venta principal');

            $ventaData = [
                // Campos de CUSPI
                'fecha' => $datos['venta']['fecha'],
                'subtotal0' => 0.00, // ✅ CORREGIDO: Siempre 0.00 en ventas normales
                'subtotal' => $datos['venta']['subtotal'],
                'descuento' => $datos['venta']['descuento'] ?? 0.00,
                'total' => $datos['venta']['total'],
                'cambio' => $datos['venta']['cambio'] ?? 0.00,
                'comentario' => $datos['venta']['comentario'] ?? '',
                'status' => $datos['venta']['status'],

                // FKs
                'caj_id' => $datos['venta']['caj_id'] ?? 1,
                'mon_id' => $datos['venta']['mon_id'] ?? 1,
                'vnd_id' => $datos['venta']['vnd_id'] ?? null,
                'rcc_id' => null, // ✅ CORREGIDO: NULL (no es cliente, es resumen de corte de caja)

                // Campos CALCULADOS (necesarios para que SICAR abra la venta)
                'letra' => $letra, // ✅ CORREGIDO: Total en letras
                'peso' => 0.0000, // ✅ CORREGIDO: 0.0000 por defecto
                'totalCompra' => $totalCompra, // ✅ CORREGIDO: Calculado
                'totalUtilidad' => $totalUtilidad, // ✅ CORREGIDO: Calculado
                'subtotalCompra' => $subtotalCompra, // ✅ CORREGIDO: Calculado
                'subtotalUtilidad' => $subtotalUtilidad, // ✅ CORREGIDO: Calculado

                // Campos moneda extranjera (no se usan)
                'monSubtotal0' => null,
                'monSubtotal' => null,
                'monDescuento' => null,
                'monTotal' => null,
                'monCambio' => null,
                'monLetra' => null,
                'monAbr' => 'MXN',
                'monTipoCambio' => $datos['venta']['tipoCambio'] ?? 1.000000,
                'decimales' => $ventaConf->decimales ?? 2,
                'porPeriodo' => 0,
                'ventaPorAjuste' => 0,
                'puntos' => null,
                'monedas' => null,
                'afStatus' => null,
                'afConsumo' => null,
                'afFechaVencimiento' => null,
                'afFechaSolicitud' => null,
                'afUsoCfdi' => null,
                'afCliente' => null,
                'afFolio' => null,
                'afGrupo' => null,
                'afCodPostal' => null,
                'afRegimen' => null,
                'afEmail' => null,
                'origen' => null,
                'monedero' => null,
                'monMonedero' => null,
                'totalNor' => null,
                'monTotalNor' => null,
                'diferenciaTotal' => null,
                'monDiferenciaTotal' => null,
                'tic_id' => null,
                'not_id' => null,
                'rem_id' => null,
                'can_caj_id' => null,
                'can_rcc_id' => null,
                'rut_id' => null
            ];

            $venId = DB::table('venta')->insertGetId($ventaData);

            Log::info('TUNNEL VENTAS: Venta principal insertada', ['ven_id' => $venId]);

            // ======================================================================
            // PASO 2: Folio = ven_id (SICAR usa ven_id como folio)
            // ======================================================================
            // No se genera folio separado, el ven_id ES el folio

            // ======================================================================
            // PASO 3: INSERT INTO detallev (artículos)
            // ======================================================================
            Log::info('TUNNEL VENTAS: Paso 3 - Insertando detalles de venta', [
                'cantidad_articulos' => count($datos['detalles'])
            ]);

            foreach ($datos['detalles'] as $detalle) {
                DB::table('detallev')->insert([
                    // Campos de CUSPI
                    'ven_id' => $venId,
                    'art_id' => $detalle['art_id'],
                    'clave' => $detalle['clave'],
                    'descripcion' => $detalle['descripcion'],
                    'cantidad' => $detalle['cantidad'],
                    'unidad' => $detalle['unidad'],
                    'precioSin' => $detalle['precioSin'],
                    'precioCon' => $detalle['precioCon'],
                    'importeSin' => $detalle['importeSin'],
                    'importeCon' => $detalle['importeCon'],
                    'descPorcentaje' => $detalle['descPorcentaje'] ?? 0.00,
                    'descTotal' => $detalle['descTotal'] ?? 0.00,
                    'precioCompra' => $detalle['precioCompra'],
                    'orden' => $detalle['orden'],

                    // Campos con valores por defecto (necesarios para que SICAR abra la venta)
                    'precioNorSin' => $detalle['precioSin'],
                    'precioNorCon' => $detalle['precioCon'],
                    'importeNorSin' => $detalle['importeSin'],
                    'importeNorCon' => $detalle['importeCon'],
                    'importeCompra' => $detalle['precioCompra'] * $detalle['cantidad'],
                    'sinGravar' => 0,
                    'caracteristicas' => '',
                    'detImp' => !empty($datos['detallesImpuestos']) ? 1 : 0,
                    'iepsActivo' => 0,
                    'cuotaIeps' => 0.00,
                    'cuentaPredial' => '',
                    'movVen' => 0,
                    'movVenC' => 0,
                    'monPrecioNorSin' => null,
                    'monPrecioNorCon' => null,
                    'monPrecioSin' => null,
                    'monPrecioCon' => null,
                    'monImporteNorSin' => null,
                    'monImporteNorCon' => null,
                    'monImporteSin' => null,
                    'monImporteCon' => null,
                    'monDescTotal' => null,
                    'nombreAduana' => null,
                    'fechaDocAduanero' => null,
                    'numeroDocAduanero' => null,
                    'claveProdServ' => null,
                    'claveUnidad' => null,
                    'descuentoFac' => null,
                    'monDescuentoFac' => null,
                    'subtotalCompra' => null,
                    'monedero' => null,
                    'monMonedero' => null,
                    'lote' => 0,
                    'receta' => 0,
                    'tipo' => 0,
                    'trr_id' => null,
                    'ncr_id' => null,
                    'mem_id' => null,
                    'diasVigencia' => null,
                    'localizacion' => null,
                    'precioConAntFac' => null,
                    'importeConAntFac' => null
                ]);
            }

            // ======================================================================
            // PASO 4: INSERT INTO detallevimpuesto (impuestos por artículo)
            // ======================================================================
            if (!empty($datos['detallesImpuestos'])) {
                Log::info('TUNNEL VENTAS: Paso 4 - Insertando impuestos por artículo', [
                    'cantidad_impuestos' => count($datos['detallesImpuestos'])
                ]);

                foreach ($datos['detallesImpuestos'] as $impuesto) {
                    // Obtener nombre del impuesto
                    $impuestoInfo = DB::table('impuesto')->where('imp_id', $impuesto['imp_id'])->first();

                    DB::table('detallevimpuesto')->insert([
                        'ven_id' => $venId,
                        'art_id' => $impuesto['art_id'],
                        'imp_id' => $impuesto['imp_id'],
                        'nombre' => $impuestoInfo->nombre,
                        'impuesto' => $impuesto['tasa'],  // tasa → impuesto
                        'tras' => 1,
                        'total' => $impuesto['importe'],  // importe → total
                        'monTotal' => null,
                        'tipoFactor' => $impuestoInfo->tipoFactor ?? null,
                        'aplicaIVA' => $impuestoInfo->aplicarIVA ?? null
                    ]);
                }
            }

            // ======================================================================
            // PASO 5: INSERT INTO ventaimp (impuestos generales)
            // ======================================================================
            if (!empty($datos['impuestos'])) {
                Log::info('TUNNEL VENTAS: Paso 5 - Insertando impuestos generales', [
                    'cantidad_impuestos' => count($datos['impuestos'])
                ]);

                $orden = 1;
                foreach ($datos['impuestos'] as $impuesto) {
                    DB::table('ventaimp')->insert([
                        'ven_id' => $venId,
                        'imp_id' => $impuesto['imp_id'],
                        'subtotal' => $impuesto['base'],  // base → subtotal
                        'total' => $impuesto['importe'],   // importe → total
                        'tras' => 1,
                        'orden' => $orden++,
                        'aplicaIVA' => null,
                        'monSubtotal' => null,
                        'monTotal' => null
                    ]);
                }
            }

            // ======================================================================
            // PASO 6: INSERT INTO ventatipopago (formas de pago)
            // ======================================================================
            Log::info('TUNNEL VENTAS: Paso 6 - Insertando formas de pago', [
                'cantidad_formas' => count($datos['formasPago'])
            ]);

            foreach ($datos['formasPago'] as $pago) {
                DB::table('ventatipopago')->insert([
                    'ven_id' => $venId,
                    'tpa_id' => $pago['tpa_id'],
                    'total' => $pago['importe'],  // importe → total
                    'monTotal' => $pago['importe']
                ]);
            }

            // ======================================================================
            // PASO 7: UPDATE existencia (descuenta inventario)
            // ======================================================================
            Log::info('TUNNEL VENTAS: Paso 7 - Actualizando existencias');

            foreach ($datos['detalles'] as $detalle) {
                // Verificar existencia actual (está en tabla articulo)
                $existenciaActual = DB::table('articulo')
                    ->where('art_id', $detalle['art_id'])
                    ->value('existencia');

                // Validar solo si ventaconf lo requiere
                if ($ventaConf && !$ventaConf->venderSinInv) {
                    if ($existenciaActual < $detalle['cantidad']) {
                        throw new \Exception("Existencia insuficiente para artículo ID {$detalle['art_id']}");
                    }
                }

                // Descontar inventario (tabla articulo)
                DB::table('articulo')
                    ->where('art_id', $detalle['art_id'])
                    ->decrement('existencia', $detalle['cantidad']);
            }

            // ======================================================================
            // PASO 8: INSERT INTO movimientoinventario (registro de movimientos)
            // ======================================================================
            // NOTA: SICAR no registra movimientos de inventario para ventas
            // La existencia se actualiza directamente en tabla articulo
            Log::info('TUNNEL VENTAS: Paso 8 - Movimientos de inventario (omitido - SICAR no los registra)');

            // ======================================================================
            // PASO 9: INSERT INTO creditocliente (SOLO si es venta a crédito)
            // ======================================================================
            if (!empty($datos['creditoCliente'])) {
                Log::info('TUNNEL VENTAS: Paso 9 - Insertando crédito de cliente');

                DB::table('creditocliente')->insert([
                    'cli_id' => $datos['venta']['cli_id'],
                    'ven_id' => $venId,
                    'fechaLimite' => $datos['creditoCliente']['fechaLimite'],
                    'total' => $datos['creditoCliente']['total'],
                    'comentario' => $datos['creditoCliente']['comentario'] ?? '',
                    'status' => $datos['creditoCliente']['status'] ?? 1
                ]);
            }

            // ======================================================================
            // PASO 10: INSERT INTO ventanotacredito (SOLO si se aplicaron notas)
            // ======================================================================
            if (!empty($datos['notasCredito'])) {
                Log::info('TUNNEL VENTAS: Paso 10 - Insertando notas de crédito', [
                    'cantidad_notas' => count($datos['notasCredito'])
                ]);

                foreach ($datos['notasCredito'] as $nota) {
                    DB::table('ventanotacredito')->insert([
                        'ven_id' => $venId,
                        'ncr_id' => $nota['ncr_id'],
                        'total' => $nota['importe']
                    ]);
                }
            }

            // ======================================================================
            // COMMIT - Todo exitoso
            // ======================================================================
            DB::commit();

            Log::info('TUNNEL VENTAS: Venta creada exitosamente en SICAR', [
                'ven_id' => $venId
            ]);

            // Respuesta según especificación CUSPI
            return response()->json([
                'success' => true,
                'message' => 'Venta insertada exitosamente',
                'data' => [
                    'ven_id' => $venId
                ]
            ], 201);

        } catch (\Exception $e) {
            // ROLLBACK - Cualquier error deshace TODA la transacción
            DB::rollBack();

            Log::error('TUNNEL VENTAS: Error al crear venta', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

}
