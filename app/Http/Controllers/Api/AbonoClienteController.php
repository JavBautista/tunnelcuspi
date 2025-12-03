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
                'usu_id' => 'nullable|integer'
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
            // ESTRUCTURA PARA RETORNAR TODO LO INSERTADO A CUSPI
            // ======================================================================
            $insertados = [
                'abonocliente' => null,
                'movimiento' => null,
                'caja' => null,
                'historial' => null,
                'creditocliente' => null
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
                'insertados' => $insertados
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
}
