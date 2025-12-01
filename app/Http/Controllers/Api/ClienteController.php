<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * ==================================================================================
 * CONTROLADOR DE CLIENTES PARA SICAR
 * ==================================================================================
 *
 * Este controlador replica EXACTAMENTE el comportamiento del módulo de clientes
 * de SICAR para que los clientes creados desde CUSPI sean 100% compatibles.
 *
 * BASADO EN:
 * - Análisis de SICAR: /home/dev/Proyectos/dev_sicar/docs/01_MODULOS/CLIENTES/ANALISIS_MODULO_CLIENTES.md
 * - Módulo: secliente-4.0.jar (DCliente.guardarCliente)
 *
 * TABLAS AFECTADAS:
 * 1. cliente - INSERT principal (37 campos)
 * 2. historial - INSERT auditoría (movimiento=0 para crear)
 * 3. cuentacliente - INSERT si hay cuentas bancarias (opcional)
 * 4. clientecomplemento - INSERT si hay complementos CFDI (opcional)
 *
 * @author TunnelCUSPI Development Team
 * @version 1.0
 */
class ClienteController extends Controller
{
    /**
     * Crear cliente en SICAR desde CUSPI
     *
     * Endpoint: POST /api/clientes
     *
     * Replica exactamente el flujo de DCliente.guardarCliente() de SICAR
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            Log::info('TUNNEL CLIENTES: Iniciando creación de cliente en SICAR');

            // Iniciar transacción
            DB::beginTransaction();

            // ======================================================================
            // VALIDACIONES
            // ======================================================================
            $validator = Validator::make($request->all(), [
                'cliente' => 'required|array',

                // ÚNICO campo realmente obligatorio
                'cliente.nombre' => 'required|string|max:1000',

                // Todos los demás son OPCIONALES (SICAR permite guardar solo con nombre)
                'cliente.representante' => 'nullable|string|max:1000',
                'cliente.domicilio' => 'nullable|string|max:120',
                'cliente.noExt' => 'nullable|string|max:45',
                'cliente.noInt' => 'nullable|string|max:45',
                'cliente.localidad' => 'nullable|string|max:120',
                'cliente.ciudad' => 'nullable|string|max:120',
                'cliente.estado' => 'nullable|string|max:45',
                'cliente.pais' => 'nullable|string|max:45',
                'cliente.codigoPostal' => 'nullable|string|max:10',
                'cliente.colonia' => 'nullable|string|max:45',
                'cliente.rfc' => 'nullable|string|max:45',
                'cliente.curp' => 'nullable|string|max:45',
                'cliente.telefono' => 'nullable|string|max:45',
                'cliente.celular' => 'nullable|string|max:45',
                'cliente.mail' => 'nullable|string|max:255',
                'cliente.comentario' => 'nullable|string|max:255',
                'cliente.status' => 'nullable|integer|in:1,-1',
                'cliente.limite' => 'nullable|numeric|min:0',
                'cliente.precio' => 'nullable|integer|min:1|max:5',
                'cliente.diasCredito' => 'nullable|integer|min:0',
                'cliente.retener' => 'nullable|boolean',
                'cliente.desglosarIEPS' => 'nullable|boolean',
                'cliente.notificar' => 'nullable|boolean',
                'cliente.clave' => 'nullable|string|max:45',
                'cliente.usoCfdi' => 'nullable|string|max:10',
                'cliente.idCIF' => 'nullable|string|max:20',
                'cliente.grc_id' => 'nullable|integer',
                'cliente.rgf_id' => 'nullable|integer',

                // Campos educación (opcionales)
                'cliente.eduNivel' => 'nullable|string|max:128',
                'cliente.eduClave' => 'nullable|string|max:128',
                'cliente.eduRfc' => 'nullable|string|max:45',
                'cliente.eduNombre' => 'nullable|string|max:120',

                // Usuario que crea (para historial)
                'usu_id' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                throw new \Exception('Datos inválidos: ' . implode(', ', $validator->errors()->all()));
            }

            $datos = $validator->validated();
            $clienteData = $datos['cliente'];

            // ======================================================================
            // VALIDAR CLAVE ÚNICA (si se envía)
            // ======================================================================
            if (!empty($clienteData['clave'])) {
                $claveExiste = DB::table('cliente')
                    ->where('clave', $clienteData['clave'])
                    ->exists();

                if ($claveExiste) {
                    throw new \Exception("La clave '{$clienteData['clave']}' ya existe en otro cliente");
                }
            }

            // ======================================================================
            // VALIDAR GRUPO DE CLIENTE (si se envía)
            // ======================================================================
            if (!empty($clienteData['grc_id'])) {
                $grupoExiste = DB::table('grupocliente')
                    ->where('grc_id', $clienteData['grc_id'])
                    ->where('status', 1)
                    ->exists();

                if (!$grupoExiste) {
                    throw new \Exception("El grupo de cliente ID {$clienteData['grc_id']} no existe o está inactivo");
                }
            }

            // ======================================================================
            // VALIDAR RÉGIMEN FISCAL (si se envía)
            // ======================================================================
            if (!empty($clienteData['rgf_id'])) {
                $regimenExiste = DB::table('regimenfiscal')
                    ->where('rgf_id', $clienteData['rgf_id'])
                    ->exists();

                if (!$regimenExiste) {
                    throw new \Exception("El régimen fiscal ID {$clienteData['rgf_id']} no existe");
                }
            }

            // ======================================================================
            // ESTRUCTURA PARA RETORNAR A CUSPI
            // ======================================================================
            $insertados = [
                'cliente' => null,
                'historial' => null
            ];

            // ======================================================================
            // PASO 1: INSERT INTO cliente
            // Basado en: DCliente.guardarCliente() - clienteController.create(cliente)
            // ======================================================================
            Log::info('TUNNEL CLIENTES: Paso 1 - Insertando cliente');

            $fechaLocal = now()->format('Y-m-d H:i:s');

            $clienteInsert = [
                // Datos básicos (solo nombre es obligatorio)
                'nombre' => $clienteData['nombre'],
                'representante' => $clienteData['representante'] ?? '',

                // Dirección (todos opcionales, default "" como SICAR)
                'domicilio' => $clienteData['domicilio'] ?? '',
                'noExt' => $clienteData['noExt'] ?? '',
                'noInt' => $clienteData['noInt'] ?? '',
                'localidad' => $clienteData['localidad'] ?? '',
                'ciudad' => $clienteData['ciudad'] ?? '',
                'estado' => $clienteData['estado'] ?? '',
                'pais' => $clienteData['pais'] ?? '',
                'codigoPostal' => $clienteData['codigoPostal'] ?? '',
                'colonia' => $clienteData['colonia'] ?? '',

                // Identificación fiscal (opcionales)
                'rfc' => $clienteData['rfc'] ?? '',
                'curp' => $clienteData['curp'] ?? '',

                // Contacto (opcionales)
                'telefono' => $clienteData['telefono'] ?? '',
                'celular' => $clienteData['celular'] ?? '',
                'mail' => $clienteData['mail'] ?? '',
                'comentario' => $clienteData['comentario'] ?? '',

                // Estado y configuración (con defaults como SICAR)
                'status' => $clienteData['status'] ?? 1,
                'limite' => $clienteData['limite'] ?? 0.00,
                'precio' => $clienteData['precio'] ?? 1,
                'diasCredito' => $clienteData['diasCredito'] ?? 0,
                'retener' => $clienteData['retener'] ?? 0,
                'desglosarIEPS' => $clienteData['desglosarIEPS'] ?? 0,
                'notificar' => $clienteData['notificar'] ?? 1,
                'clave' => $clienteData['clave'] ?? null,

                // CFDI y facturación (opcionales)
                'usoCfdi' => $clienteData['usoCfdi'] ?? null,
                'idCIF' => $clienteData['idCIF'] ?? null,

                // Campos blob (no se usan desde CUSPI)
                'foto' => null,
                'huella' => null,
                'muestra' => null,

                // SICAR Nube (no se usa)
                'sid' => null,

                // Educación (opcionales)
                'eduNivel' => $clienteData['eduNivel'] ?? null,
                'eduClave' => $clienteData['eduClave'] ?? null,
                'eduRfc' => $clienteData['eduRfc'] ?? null,
                'eduNombre' => $clienteData['eduNombre'] ?? null,

                // FKs (opcionales)
                'grc_id' => $clienteData['grc_id'] ?? null,
                'rgf_id' => $clienteData['rgf_id'] ?? null
            ];

            $cliId = DB::table('cliente')->insertGetId($clienteInsert);
            $insertados['cliente'] = array_merge(['cli_id' => $cliId], $clienteInsert);

            Log::info('TUNNEL CLIENTES: Cliente insertado', ['cli_id' => $cliId]);

            // ======================================================================
            // PASO 2: INSERT INTO historial (auditoría)
            // Basado en: SICAR siempre registra cambios en historial
            // movimiento: 0=Crear, 1=Editar, 2=Eliminar
            // ======================================================================
            Log::info('TUNNEL CLIENTES: Paso 2 - Registrando en historial');

            $usuId = $datos['usu_id'] ?? 23; // 23 = CUSPIBOT

            $historialData = [
                'movimiento' => 0,          // 0 = Creación
                'fecha' => $fechaLocal,
                'tabla' => 'Cliente',       // Con C mayúscula (como SICAR)
                'id' => $cliId,
                'usu_id' => $usuId
            ];

            DB::table('historial')->insert($historialData);
            $insertados['historial'] = $historialData;

            Log::info('TUNNEL CLIENTES: Historial registrado', [
                'tabla' => 'Cliente',
                'id' => $cliId,
                'usu_id' => $usuId
            ]);

            // ======================================================================
            // COMMIT
            // ======================================================================
            DB::commit();

            Log::info('TUNNEL CLIENTES: Cliente creado exitosamente en SICAR', [
                'cli_id' => $cliId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cliente creado exitosamente',
                'cli_id' => $cliId,
                'insertados' => $insertados
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('TUNNEL CLIENTES: Error al crear cliente', [
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
