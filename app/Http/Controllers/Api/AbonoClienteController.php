<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * ==================================================================================
 * CONTROLADOR DE ABONOS A CRÉDITO PARA SICAR
 * ==================================================================================
 *
 * Este controlador replica EXACTAMENTE el comportamiento del módulo de abonos
 * de SICAR (Consultas > Clientes Créditos > Abono F3) para que los abonos
 * creados desde CUSPI se registren correctamente en SICAR.
 *
 * BASADO EN:
 * - Análisis de SICAR: /home/dev/Proyectos/dev_sicar/docs/01_MODULOS/CLIENTES_CREDITOS/
 * - Documento clave: 02_MODAL_CREDITOS/01_ABONO.md
 * - Clases Java: DAbono.java, AbonoLogic.java, AbonoClienteController.java
 *
 * FLUJO (5 pasos exactos de SICAR):
 * 1. INSERT INTO abonocliente → obtener acl_id
 * 2. INSERT INTO movimiento (tipo=1, entrada de dinero)
 * 3. UPDATE caja (incrementar total)
 * 4. INSERT INTO historial (tabla='AbonoCliente', movimiento=0)
 * 5. UPDATE creditocliente SET status=2 (SOLO si saldo queda en 0)
 *
 * @author TunnelCUSPI Development Team
 * @version 1.0
 */
class AbonoClienteController extends Controller
{
    /**
     * Tipos de pago válidos para abonos
     * NOTA: tpa_id=3 (Crédito) y tpa_id=7 (Anticipo) NO son válidos para abonos
     */
    private const TIPOS_PAGO_VALIDOS = [1, 2, 4, 5, 6];

    /**
     * Registrar abono a crédito en SICAR
     *
     * Endpoint: POST /api/abonos/crear
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            Log::info('TUNNEL ABONOS: Iniciando registro de abono a crédito');

            // ======================================================================
            // VALIDACIONES DE ENTRADA
            // ======================================================================
            $validator = Validator::make($request->all(), [
                'ccl_id' => 'required|integer',
                'monto' => 'required|numeric|min:0.01',
                'tpa_id' => 'required|integer',
                'referencia' => 'nullable|string|max:255',
                'caj_id' => 'nullable|integer',
                'usu_id' => 'nullable|integer',
                // Tarjeta (tpa_id=6): tipo_tarjeta es OBLIGATORIO
                'tipo_tarjeta' => 'nullable|integer|in:0,1', // 0=Crédito, 1=Débito
                // Sync delta: último acl_id conocido por CUSPI para este crédito
                'last_known_acl_id' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                throw new \Exception('Datos inválidos: ' . implode(', ', $validator->errors()->all()));
            }

            $datos = $validator->validated();

            // ======================================================================
            // VALIDAR TIPO DE PAGO
            // ======================================================================
            if (!in_array($datos['tpa_id'], self::TIPOS_PAGO_VALIDOS)) {
                throw new \Exception('Tipo de pago no válido para abonos. Válidos: 1=Efectivo, 2=Cheque, 4=Transferencia, 5=Vales, 6=Tarjeta');
            }

            // Si es Tarjeta (tpa_id=6), tipo_tarjeta es OBLIGATORIO
            if ($datos['tpa_id'] == 6 && !isset($datos['tipo_tarjeta'])) {
                throw new \Exception('Para pago con Tarjeta (tpa_id=6) debe especificar tipo_tarjeta: 0=Crédito, 1=Débito');
            }

            // ======================================================================
            // VALIDAR QUE EL CRÉDITO EXISTE Y ESTÁ VIGENTE
            // ======================================================================
            $credito = DB::table('creditocliente')
                ->where('ccl_id', $datos['ccl_id'])
                ->where('status', 1) // 1 = Vigente
                ->first();

            if (!$credito) {
                throw new \Exception('Crédito no encontrado o no está vigente (status != 1)');
            }

            Log::info('TUNNEL ABONOS: Crédito encontrado', [
                'ccl_id' => $credito->ccl_id,
                'cli_id' => $credito->cli_id,
                'total_credito' => $credito->total
            ]);

            // ======================================================================
            // CALCULAR SALDO ACTUAL DEL CRÉDITO
            // Fórmula: saldo = total - abonos - notas_credito
            // ======================================================================
            $saldoActual = $this->calcularSaldoCredito($credito->ccl_id, $credito->total);

            Log::info('TUNNEL ABONOS: Saldo calculado', [
                'ccl_id' => $credito->ccl_id,
                'total_credito' => $credito->total,
                'saldo_actual' => $saldoActual
            ]);

            // ======================================================================
            // VALIDAR QUE EL MONTO NO EXCEDE EL SALDO
            // ======================================================================
            if ($datos['monto'] > $saldoActual) {
                throw new \Exception(
                    "El monto ($" . number_format($datos['monto'], 2) .
                    ") excede el saldo pendiente ($" . number_format($saldoActual, 2) . ")"
                );
            }

            // ======================================================================
            // VALIDAR CAJA
            // ======================================================================
            $cajId = $datos['caj_id'] ?? 1; // Default: caja 1 (MARY)
            $caja = DB::table('caja')->where('caj_id', $cajId)->first();

            if (!$caja) {
                throw new \Exception("Caja ID {$cajId} no encontrada");
            }

            // ======================================================================
            // PREPARAR DATOS
            // ======================================================================
            $usuId = $datos['usu_id'] ?? 23; // Default: CUSPIBOT
            $referencia = $datos['referencia'] ?? '';
            $fechaActual = now()->format('Y-m-d');
            $fechaHoraActual = now()->format('Y-m-d H:i:s');

            // Calcular nuevo saldo después del abono
            $nuevoSaldo = $saldoActual - $datos['monto'];
            $creditoLiquidado = $nuevoSaldo <= 0.01; // Tolerancia de centavos

            Log::info('TUNNEL ABONOS: Preparando transacción', [
                'monto' => $datos['monto'],
                'saldo_anterior' => $saldoActual,
                'saldo_nuevo' => $nuevoSaldo,
                'credito_liquidado' => $creditoLiquidado
            ]);

            // ======================================================================
            // SYNC DELTA: Buscar abonos que CUSPI no conoce
            // Si CUSPI envía last_known_acl_id, buscamos abonos posteriores
            // ======================================================================
            $syncPendientes = [];
            $lastKnownAclId = $datos['last_known_acl_id'] ?? null;

            if ($lastKnownAclId !== null) {
                $syncPendientes = $this->buscarAbonosPendientes($datos['ccl_id'], $lastKnownAclId);

                if (count($syncPendientes) > 0) {
                    Log::info('TUNNEL ABONOS: Sync delta - encontrados abonos pendientes', [
                        'ccl_id' => $datos['ccl_id'],
                        'last_known_acl_id' => $lastKnownAclId,
                        'pendientes_count' => count($syncPendientes)
                    ]);
                }
            }

            // ======================================================================
            // ESTRUCTURA PARA RETORNAR TODO LO INSERTADO A CUSPI
            // ======================================================================
            $insertados = [
                'abonocliente' => null,
                'movimiento' => null,
                'caja' => null,
                'tipotarjeta' => null,    // Solo para tpa_id=6
                'historial' => null,
                'creditocliente' => null  // Solo si liquida el crédito
            ];

            // ======================================================================
            // INICIAR TRANSACCIÓN
            // ======================================================================
            DB::beginTransaction();

            // ======================================================================
            // PASO 1: INSERT INTO abonocliente
            // Ref: DAbono.java, AbonoLogic.crearAbono()
            // ======================================================================
            Log::info('TUNNEL ABONOS: Paso 1 - Insertando abonocliente');

            $abonocliente = [
                'fecha' => $fechaActual,
                'total' => $datos['monto'],
                'comentario' => $referencia,
                'status' => 1, // 1 = Activo
                'ccl_id' => $datos['ccl_id'],
                'tpa_id' => $datos['tpa_id'],
                'acp_id' => null // NULL para abono simple, ID para multipago
            ];

            $aclId = DB::table('abonocliente')->insertGetId($abonocliente);
            $insertados['abonocliente'] = array_merge(['acl_id' => $aclId], $abonocliente);

            Log::info('TUNNEL ABONOS: Abono insertado', ['acl_id' => $aclId]);

            // ======================================================================
            // PASO 2: INSERT INTO movimiento
            // Ref: AbonoLogic.crearAbono() - Registro de caja
            // tipo=1 significa entrada de dinero
            // ======================================================================
            Log::info('TUNNEL ABONOS: Paso 2 - Insertando movimiento');

            $movimiento = [
                'total' => $datos['monto'],
                'comentario' => $referencia,
                'tipo' => 1, // 1 = Entrada de dinero
                'status' => 1, // 1 = Activo
                'tipoExt' => null, // NULL en abonos
                'caj_id' => $cajId,
                'tpa_id' => $datos['tpa_id'],
                'ven_id' => null, // NULL - relación indirecta vía acl_id
                'com_id' => null,
                'acl_id' => $aclId, // FK al abono recién creado
                'apr_id' => null,
                'cor_id' => null,
                'ncr_id' => null,
                'ncp_id' => null,
                'sip_id' => null
            ];

            DB::table('movimiento')->insert($movimiento);
            $insertados['movimiento'] = $movimiento;

            Log::info('TUNNEL ABONOS: Movimiento insertado');

            // ======================================================================
            // PASO 3: UPDATE caja (incrementar total)
            // Ref: AbonoLogic.crearAbono()
            // ======================================================================
            Log::info('TUNNEL ABONOS: Paso 3 - Actualizando caja');

            $totalCajaAnterior = $caja->total;

            DB::table('caja')
                ->where('caj_id', $cajId)
                ->increment('total', $datos['monto']);

            $insertados['caja'] = [
                'caj_id' => $cajId,
                'total_anterior' => $totalCajaAnterior,
                'monto_agregado' => $datos['monto'],
                'total_nuevo' => $totalCajaAnterior + $datos['monto']
            ];

            Log::info('TUNNEL ABONOS: Caja actualizada', $insertados['caja']);

            // ======================================================================
            // PASO 3.5: INSERT INTO tipotarjeta (SOLO para tpa_id=6)
            // Ref: 02_ABONO_METODOS_PAGO.md - Tarjeta requiere registro adicional
            // tipo: 0=Crédito, 1=Débito
            // ======================================================================
            if ($datos['tpa_id'] == 6) {
                Log::info('TUNNEL ABONOS: Paso 3.5 - Insertando tipotarjeta');

                $tipotarjeta = [
                    'tipo' => $datos['tipo_tarjeta'], // 0=Crédito, 1=Débito
                    'acl_id' => $aclId,               // FK al abono
                    'ven_id' => null                  // NULL porque es abono, no venta
                ];

                $ttaId = DB::table('tipotarjeta')->insertGetId($tipotarjeta);
                $insertados['tipotarjeta'] = array_merge(['tta_id' => $ttaId], $tipotarjeta);

                Log::info('TUNNEL ABONOS: Tipotarjeta insertado', [
                    'tta_id' => $ttaId,
                    'tipo' => $datos['tipo_tarjeta'] == 0 ? 'Crédito' : 'Débito'
                ]);
            }

            // ======================================================================
            // PASO 4: INSERT INTO historial
            // Ref: AbonoLogic.crearAbono()
            // tabla='AbonoCliente', movimiento=0 (creación)
            // ======================================================================
            Log::info('TUNNEL ABONOS: Paso 4 - Insertando historial');

            $historial = [
                'id' => $aclId,
                'tabla' => 'AbonoCliente', // Nombre exacto como en SICAR
                'movimiento' => 0, // 0 = Creación
                'usu_id' => $usuId,
                'fecha' => $fechaHoraActual
            ];

            DB::table('historial')->insert($historial);
            $insertados['historial'] = $historial;

            Log::info('TUNNEL ABONOS: Historial insertado');

            // ======================================================================
            // NOTA: recepcionpago NO se inserta aquí
            // La tabla recepcionpago es para CFDI Complemento de Pago (REP)
            // Esto se genera desde SICAR cuando el crédito tiene factura PPD
            // CUSPI solo registra el abono, SICAR genera el CFDI si es necesario
            // ======================================================================

            // ======================================================================
            // PASO 5: UPDATE creditocliente (SOLO si saldo queda en 0)
            // Ref: AbonoLogic.crearAbono() - Marcar como pagado
            // status=2 significa PAGADO
            // ======================================================================
            if ($creditoLiquidado) {
                Log::info('TUNNEL ABONOS: Paso 5 - Marcando crédito como PAGADO');

                DB::table('creditocliente')
                    ->where('ccl_id', $datos['ccl_id'])
                    ->update(['status' => 2]); // 2 = Pagado

                $insertados['creditocliente'] = [
                    'ccl_id' => $datos['ccl_id'],
                    'status_anterior' => 1,
                    'status_nuevo' => 2
                ];

                Log::info('TUNNEL ABONOS: Crédito marcado como PAGADO', [
                    'ccl_id' => $datos['ccl_id']
                ]);
            }

            // ======================================================================
            // COMMIT - Todo exitoso
            // ======================================================================
            DB::commit();

            Log::info('TUNNEL ABONOS: Abono registrado exitosamente', [
                'acl_id' => $aclId,
                'ccl_id' => $datos['ccl_id'],
                'monto' => $datos['monto'],
                'credito_liquidado' => $creditoLiquidado
            ]);

            // ======================================================================
            // RESPUESTA EXITOSA
            // ======================================================================
            $mensaje = $creditoLiquidado
                ? 'Abono registrado exitosamente. Crédito liquidado.'
                : 'Abono registrado exitosamente';

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'acl_id' => $aclId,
                'creditoLiquidado' => $creditoLiquidado,
                'saldoAnterior' => round($saldoActual, 2),
                'montoAbonado' => round($datos['monto'], 2),
                'nuevoSaldo' => round(max($nuevoSaldo, 0), 2),
                'insertados' => $insertados,
                'sync_pendientes' => $syncPendientes // Abonos que CUSPI no tenía
            ], 201);

        } catch (\Exception $e) {
            // ROLLBACK - Cualquier error deshace TODA la transacción
            DB::rollBack();

            Log::error('TUNNEL ABONOS: Error al registrar abono', [
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

    /**
     * ==================================================================================
     * MULTIPAGO: Aplicar UN pago a MULTIPLES créditos
     * ==================================================================================
     *
     * Endpoint: POST /api/abonos/multipago
     *
     * DIFERENCIAS CON ABONO SIMPLE:
     * - Usa tabla padre: aboclipadre
     * - Campo acp_id en abonocliente apunta al padre (no es NULL)
     * - Crea UN movimiento POR CADA abono (no uno solo)
     * - Distribución SECUENCIAL: llena créditos en orden hasta agotar monto
     *
     * FLUJO:
     * 1. INSERT aboclipadre → obtener acp_id
     * 2. Por cada crédito (mientras haya monto):
     *    - Calcular montoAbono = min(montoRestante, saldo)
     *    - INSERT abonocliente (con acp_id)
     *    - INSERT movimiento
     *    - UPDATE caja
     *    - INSERT tipotarjeta (si tpa_id=6)
     *    - INSERT historial
     *    - UPDATE creditocliente status=2 (si liquida)
     *
     * Ref: AbonoLogic.crearMultipago(), DMultiPago.java, DSelCredito.java
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function multipago(Request $request)
    {
        try {
            Log::info('TUNNEL MULTIPAGO: Iniciando registro de multipago');

            // ======================================================================
            // VALIDACIONES DE ENTRADA
            // ======================================================================
            $validator = Validator::make($request->all(), [
                'creditos' => 'required|array|min:2',
                'creditos.*.ccl_id' => 'required|integer',
                'monto' => 'required|numeric|min:0.01',
                'tpa_id' => 'required|integer',
                'referencia' => 'nullable|string|max:255',
                'caj_id' => 'nullable|integer',
                'usu_id' => 'nullable|integer',
                'tipo_tarjeta' => 'nullable|integer|in:0,1',
                'last_known_acl_id' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                throw new \Exception('Datos inválidos: ' . implode(', ', $validator->errors()->all()));
            }

            $datos = $validator->validated();

            // ======================================================================
            // VALIDAR TIPO DE PAGO
            // ======================================================================
            if (!in_array($datos['tpa_id'], self::TIPOS_PAGO_VALIDOS)) {
                throw new \Exception('Tipo de pago no válido para abonos. Válidos: 1=Efectivo, 2=Cheque, 4=Transferencia, 5=Vales, 6=Tarjeta');
            }

            if ($datos['tpa_id'] == 6 && !isset($datos['tipo_tarjeta'])) {
                throw new \Exception('Para pago con Tarjeta (tpa_id=6) debe especificar tipo_tarjeta: 0=Crédito, 1=Débito');
            }

            // ======================================================================
            // OBTENER CRÉDITOS Y CALCULAR SALDOS
            // ======================================================================
            $creditoIds = array_column($datos['creditos'], 'ccl_id');

            $creditos = DB::table('creditocliente')
                ->whereIn('ccl_id', $creditoIds)
                ->get()
                ->keyBy('ccl_id');

            // Validar que todos existen
            foreach ($creditoIds as $cclId) {
                if (!isset($creditos[$cclId])) {
                    throw new \Exception("Crédito {$cclId} no encontrado");
                }
                if ($creditos[$cclId]->status != 1) {
                    throw new \Exception("Crédito {$cclId} no está vigente (status != 1)");
                }
            }

            // Validar que todos son del mismo cliente
            $clienteIds = $creditos->pluck('cli_id')->unique();
            if ($clienteIds->count() > 1) {
                throw new \Exception('Los créditos deben ser del mismo cliente');
            }

            // Calcular saldos de cada crédito (en el ORDEN que CUSPI los envía)
            $creditosConSaldo = [];
            $sumaSaldos = 0;

            foreach ($datos['creditos'] as $creditoInput) {
                $cclId = $creditoInput['ccl_id'];
                $credito = $creditos[$cclId];
                $saldo = $this->calcularSaldoCredito($cclId, $credito->total);

                $creditosConSaldo[] = [
                    'ccl_id' => $cclId,
                    'credito' => $credito,
                    'saldo' => $saldo
                ];
                $sumaSaldos += $saldo;
            }

            Log::info('TUNNEL MULTIPAGO: Créditos validados', [
                'cantidad' => count($creditosConSaldo),
                'suma_saldos' => $sumaSaldos,
                'monto_solicitado' => $datos['monto']
            ]);

            // ======================================================================
            // VALIDAR MONTO
            // ======================================================================
            if ($datos['monto'] > $sumaSaldos) {
                throw new \Exception(
                    "El monto ($" . number_format($datos['monto'], 2) .
                    ") excede la suma de saldos ($" . number_format($sumaSaldos, 2) . ")"
                );
            }

            // ======================================================================
            // VALIDAR CAJA
            // ======================================================================
            $cajId = $datos['caj_id'] ?? 1;
            $caja = DB::table('caja')->where('caj_id', $cajId)->first();

            if (!$caja) {
                throw new \Exception("Caja ID {$cajId} no encontrada");
            }

            // ======================================================================
            // PREPARAR DATOS
            // ======================================================================
            $usuId = $datos['usu_id'] ?? 23;
            $referencia = $datos['referencia'] ?? '';
            $fechaActual = now()->format('Y-m-d');
            $fechaHoraActual = now()->format('Y-m-d H:i:s');

            // ======================================================================
            // SYNC DELTA: Buscar abonos que CUSPI no conoce (ANTES de insertar)
            // ======================================================================
            $syncPendientes = [];
            $lastKnownAclId = $datos['last_known_acl_id'] ?? null;

            if ($lastKnownAclId !== null) {
                $syncPendientes = $this->buscarAbonosPendientesGlobal($lastKnownAclId);

                if (count($syncPendientes) > 0) {
                    Log::info('TUNNEL MULTIPAGO: Sync delta - encontrados abonos pendientes', [
                        'last_known_acl_id' => $lastKnownAclId,
                        'pendientes_count' => count($syncPendientes)
                    ]);
                }
            }

            // ======================================================================
            // ESTRUCTURA PARA RETORNAR
            // ======================================================================
            $insertados = [
                'aboclipadre' => null,
                'abonos' => [],
                'caja' => null
            ];

            $creditosLiquidados = [];
            $creditosParciales = [];
            $creditosSinAbono = [];
            $resumenDistribucion = [];

            // ======================================================================
            // INICIAR TRANSACCIÓN
            // ======================================================================
            DB::beginTransaction();

            // ======================================================================
            // PASO 1: INSERT aboclipadre (registro padre del multipago)
            // Ref: AbonoLogic.crearMultipago()
            // ======================================================================
            Log::info('TUNNEL MULTIPAGO: Paso 1 - Insertando aboclipadre');

            $aboclipadre = [
                'total' => $datos['monto'],
                'comentario' => $referencia
            ];

            $acpId = DB::table('aboclipadre')->insertGetId($aboclipadre);
            $insertados['aboclipadre'] = array_merge(['acp_id' => $acpId], $aboclipadre);

            Log::info('TUNNEL MULTIPAGO: Padre insertado', ['acp_id' => $acpId]);

            // ======================================================================
            // PASO 2-N: Distribuir monto entre créditos (SECUENCIAL)
            // ======================================================================
            $montoRestante = $datos['monto'];
            $totalCajaAnterior = $caja->total;
            $montoTotalAgregadoCaja = 0;

            foreach ($creditosConSaldo as $creditoData) {
                $cclId = $creditoData['ccl_id'];
                $saldo = $creditoData['saldo'];

                // Si ya no hay monto, este crédito no recibe abono
                if ($montoRestante <= 0) {
                    $creditosSinAbono[] = $cclId;
                    $resumenDistribucion[] = [
                        'ccl_id' => $cclId,
                        'saldoAnterior' => round($saldo, 2),
                        'montoAbonado' => 0,
                        'saldoNuevo' => round($saldo, 2),
                        'liquidado' => false
                    ];
                    continue;
                }

                // Calcular monto para este crédito
                $montoAbono = min($montoRestante, $saldo);
                $montoRestante -= $montoAbono;
                $nuevoSaldo = $saldo - $montoAbono;
                $liquidado = $nuevoSaldo <= 0.01;

                Log::info('TUNNEL MULTIPAGO: Procesando crédito', [
                    'ccl_id' => $cclId,
                    'saldo' => $saldo,
                    'monto_abono' => $montoAbono,
                    'liquidado' => $liquidado
                ]);

                // Estructura para este abono
                $abonoInsertado = [
                    'abonocliente' => null,
                    'movimiento' => null,
                    'tipotarjeta' => null,
                    'historial' => null,
                    'creditocliente' => null
                ];

                // ------------------------------------------------------------------
                // 2.1 INSERT abonocliente
                // ------------------------------------------------------------------
                $abonocliente = [
                    'fecha' => $fechaActual,
                    'total' => $montoAbono,
                    'comentario' => $referencia,
                    'status' => 1,
                    'ccl_id' => $cclId,
                    'tpa_id' => $datos['tpa_id'],
                    'acp_id' => $acpId  // FK al padre (diferencia con abono simple)
                ];

                $aclId = DB::table('abonocliente')->insertGetId($abonocliente);
                $abonoInsertado['abonocliente'] = array_merge(['acl_id' => $aclId], $abonocliente);

                // ------------------------------------------------------------------
                // 2.2 INSERT movimiento (uno por cada abono)
                // Ref: En multipago, comentario es VACÍO en movimiento
                // ------------------------------------------------------------------
                $movimiento = [
                    'total' => $montoAbono,
                    'comentario' => '',  // Vacío en multipago (según análisis SICAR)
                    'tipo' => 1,
                    'status' => 1,
                    'tipoExt' => null,  // NULL en multipago
                    'caj_id' => $cajId,
                    'tpa_id' => $datos['tpa_id'],
                    'ven_id' => null,
                    'com_id' => null,
                    'acl_id' => $aclId,
                    'apr_id' => null,
                    'cor_id' => null,
                    'ncr_id' => null,
                    'ncp_id' => null,
                    'sip_id' => null
                ];

                DB::table('movimiento')->insert($movimiento);
                $abonoInsertado['movimiento'] = $movimiento;

                // ------------------------------------------------------------------
                // 2.3 UPDATE caja
                // ------------------------------------------------------------------
                DB::table('caja')
                    ->where('caj_id', $cajId)
                    ->increment('total', $montoAbono);

                $montoTotalAgregadoCaja += $montoAbono;

                // ------------------------------------------------------------------
                // 2.4 INSERT tipotarjeta (solo si tpa_id=6)
                // ------------------------------------------------------------------
                if ($datos['tpa_id'] == 6) {
                    $tipotarjeta = [
                        'tipo' => $datos['tipo_tarjeta'],
                        'acl_id' => $aclId,
                        'ven_id' => null
                    ];

                    $ttaId = DB::table('tipotarjeta')->insertGetId($tipotarjeta);
                    $abonoInsertado['tipotarjeta'] = array_merge(['tta_id' => $ttaId], $tipotarjeta);
                }

                // ------------------------------------------------------------------
                // 2.5 INSERT historial
                // ------------------------------------------------------------------
                $historial = [
                    'id' => $aclId,
                    'tabla' => 'AbonoCliente',
                    'movimiento' => 0,
                    'usu_id' => $usuId,
                    'fecha' => $fechaHoraActual
                ];

                DB::table('historial')->insert($historial);
                $abonoInsertado['historial'] = $historial;

                // ------------------------------------------------------------------
                // 2.6 UPDATE creditocliente (si liquida)
                // ------------------------------------------------------------------
                if ($liquidado) {
                    DB::table('creditocliente')
                        ->where('ccl_id', $cclId)
                        ->update(['status' => 2]);

                    $abonoInsertado['creditocliente'] = [
                        'ccl_id' => $cclId,
                        'status_anterior' => 1,
                        'status_nuevo' => 2
                    ];

                    $creditosLiquidados[] = $cclId;
                } else {
                    $creditosParciales[] = $cclId;
                }

                // Agregar a insertados
                $insertados['abonos'][] = $abonoInsertado;

                // Resumen de distribución
                $resumenDistribucion[] = [
                    'ccl_id' => $cclId,
                    'saldoAnterior' => round($saldo, 2),
                    'montoAbonado' => round($montoAbono, 2),
                    'saldoNuevo' => round(max($nuevoSaldo, 0), 2),
                    'liquidado' => $liquidado
                ];
            }

            // Información de caja (consolidada)
            $insertados['caja'] = [
                'caj_id' => $cajId,
                'total_anterior' => $totalCajaAnterior,
                'monto_agregado' => $montoTotalAgregadoCaja,
                'total_nuevo' => $totalCajaAnterior + $montoTotalAgregadoCaja
            ];

            // ======================================================================
            // COMMIT
            // ======================================================================
            DB::commit();

            Log::info('TUNNEL MULTIPAGO: Multipago registrado exitosamente', [
                'acp_id' => $acpId,
                'monto_total' => $datos['monto'],
                'creditos_liquidados' => count($creditosLiquidados),
                'creditos_parciales' => count($creditosParciales),
                'creditos_sin_abono' => count($creditosSinAbono)
            ]);

            // ======================================================================
            // RESPUESTA
            // ======================================================================
            $cantidadLiquidados = count($creditosLiquidados);
            $mensaje = "Multipago registrado exitosamente. {$cantidadLiquidados} crédito(s) liquidado(s).";

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'acp_id' => $acpId,
                'montoTotal' => round($datos['monto'], 2),
                'creditosLiquidados' => $creditosLiquidados,
                'creditosParciales' => $creditosParciales,
                'creditosSinAbono' => $creditosSinAbono,
                'resumenDistribucion' => $resumenDistribucion,
                'insertados' => $insertados,
                'sync_pendientes' => $syncPendientes
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('TUNNEL MULTIPAGO: Error al registrar multipago', [
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

    /**
     * Buscar abonos pendientes de sincronizar a CUSPI (GLOBAL)
     *
     * A diferencia de buscarAbonosPendientes(), esta versión busca
     * TODOS los abonos mayores al ID conocido, sin filtrar por crédito.
     *
     * @param int $lastKnownAclId Último acl_id que CUSPI conoce
     * @return array Lista de abonos con sus registros relacionados
     */
    private function buscarAbonosPendientesGlobal(int $lastKnownAclId): array
    {
        $abonosFaltantes = DB::table('abonocliente')
            ->where('acl_id', '>', $lastKnownAclId)
            ->orderBy('acl_id')
            ->get();

        if ($abonosFaltantes->isEmpty()) {
            return [];
        }

        $resultado = [];

        foreach ($abonosFaltantes as $abono) {
            $aclId = $abono->acl_id;

            $movimiento = DB::table('movimiento')
                ->where('acl_id', $aclId)
                ->first();

            $historial = DB::table('historial')
                ->where('tabla', 'AbonoCliente')
                ->where('id', $aclId)
                ->first();

            $tipotarjeta = DB::table('tipotarjeta')
                ->where('acl_id', $aclId)
                ->first();

            // También buscar si pertenece a un multipago
            $aboclipadre = null;
            if ($abono->acp_id) {
                $aboclipadre = DB::table('aboclipadre')
                    ->where('acp_id', $abono->acp_id)
                    ->first();
            }

            $resultado[] = [
                'abonocliente' => (array) $abono,
                'movimiento' => $movimiento ? (array) $movimiento : null,
                'historial' => $historial ? (array) $historial : null,
                'tipotarjeta' => $tipotarjeta ? (array) $tipotarjeta : null,
                'aboclipadre' => $aboclipadre ? (array) $aboclipadre : null
            ];
        }

        return $resultado;
    }

    /**
     * Calcular saldo actual de un crédito
     *
     * Fórmula SICAR:
     * saldo = total_credito - abonos_validos - notas_credito
     *
     * Donde abonos_validos excluye:
     * - Abonos con status = -1 (cancelados)
     * - Abonos cuyo recepcionpago tiene status = -1 (CFDI cancelado)
     *
     * @param int $cclId
     * @param float $totalCredito
     * @return float
     */
    private function calcularSaldoCredito(int $cclId, float $totalCredito): float
    {
        // Sumar abonos válidos (excluyendo cancelados y con CFDI cancelado)
        $totalAbonos = DB::table('abonocliente as ac')
            ->where('ac.ccl_id', $cclId)
            ->where('ac.status', '!=', -1)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('abonocliente as ac2')
                    ->join('recepcionpago as rp2', 'rp2.acl_id', '=', 'ac2.acl_id')
                    ->whereRaw('(ac2.acl_id = ac.acl_id OR ac2.acp_id = ac.acp_id)')
                    ->where('rp2.status', '=', -1);
            })
            ->sum('ac.total');

        // Sumar notas de crédito activas
        $totalNotasCredito = DB::table('creditoclientenotcre')
            ->where('ccl_id', $cclId)
            ->where('status', 1)
            ->sum('total');

        $saldo = $totalCredito - ($totalAbonos ?? 0) - ($totalNotasCredito ?? 0);

        return max($saldo, 0); // No puede ser negativo
    }

    /**
     * Buscar abonos pendientes de sincronizar a CUSPI
     *
     * Retorna todos los abonos de un crédito cuyo acl_id sea mayor
     * al último conocido por CUSPI, junto con sus tablas relacionadas.
     *
     * @param int $cclId ID del crédito
     * @param int $lastKnownAclId Último acl_id que CUSPI conoce
     * @return array Lista de abonos con sus registros relacionados
     */
    private function buscarAbonosPendientes(int $cclId, int $lastKnownAclId): array
    {
        // Buscar abonos que CUSPI no conoce
        $abonosFaltantes = DB::table('abonocliente')
            ->where('ccl_id', $cclId)
            ->where('acl_id', '>', $lastKnownAclId)
            ->orderBy('acl_id')
            ->get();

        if ($abonosFaltantes->isEmpty()) {
            return [];
        }

        $resultado = [];

        foreach ($abonosFaltantes as $abono) {
            $aclId = $abono->acl_id;

            // Buscar movimiento relacionado
            $movimiento = DB::table('movimiento')
                ->where('acl_id', $aclId)
                ->first();

            // Buscar historial relacionado
            $historial = DB::table('historial')
                ->where('tabla', 'AbonoCliente')
                ->where('id', $aclId)
                ->first();

            // Buscar tipotarjeta si existe (solo para pagos con tarjeta)
            $tipotarjeta = DB::table('tipotarjeta')
                ->where('acl_id', $aclId)
                ->first();

            $resultado[] = [
                'abonocliente' => (array) $abono,
                'movimiento' => $movimiento ? (array) $movimiento : null,
                'historial' => $historial ? (array) $historial : null,
                'tipotarjeta' => $tipotarjeta ? (array) $tipotarjeta : null
            ];
        }

        return $resultado;
    }
}
