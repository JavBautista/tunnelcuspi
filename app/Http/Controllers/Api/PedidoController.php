<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Pedido;
use App\Models\DetallePedido;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PedidoController extends Controller
{
    /**
     * Crear pedido en SICAR - Recibe pedido de CUSPI, guarda en SICAR y devuelve con IDs reales
     * CUSPI enviará pedidos aquí primero, TUNNEL guarda en SICAR, devuelve pedido, CUSPI sincroniza local
     */
    public function crear(Request $request)
    {
        try {
            DB::beginTransaction();

            // 1. Validaciones de entrada según especificaciones CUSPI
            $validator = Validator::make($request->all(), [
                'pro_id' => 'required|integer',
                'comentario' => 'nullable|string|max:1000',
                'opciones' => 'required|array',
                'opciones.img' => 'boolean',
                'opciones.caracteristicas' => 'boolean', 
                'opciones.desglosado' => 'boolean',
                'opciones.mostrarPrecios' => 'boolean',
                'opciones.mostrarClaveAlterna' => 'boolean',
                'articulos' => 'required|array|min:1',
                'articulos.*.art_id' => 'required|integer',
                'articulos.*.cantidad' => 'required|numeric|min:0.0001',
                'articulos.*.precioCompra' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Datos inválidos: ' . implode(', ', $validator->errors()->all())
                ], 400);
            }

            $datos = $validator->validated();

            // 2. Validar Proveedor existe y está activo
            $proveedor = DB::table('proveedor')
                ->where('pro_id', $datos['pro_id'])
                ->where('status', 1)
                ->first();

            if (!$proveedor) {
                throw new \Exception("Proveedor ID {$datos['pro_id']} no existe o está inactivo");
            }

            // 3. Validar y procesar artículos
            $articulosValidos = [];
            $totalPedido = 0;
            $orden = 1;

            foreach ($datos['articulos'] as $articuloData) {
                // Validar artículo existe y está activo
                $articulo = DB::table('articulo')
                    ->where('art_id', $articuloData['art_id'])
                    ->where('status', 1)
                    ->first();

                if (!$articulo) {
                    throw new \Exception("Artículo ID {$articuloData['art_id']} no existe o está inactivo");
                }

                $cantidad = floatval($articuloData['cantidad']);
                $precioCompra = floatval($articuloData['precioCompra']);
                $importeCompra = $cantidad * $precioCompra;
                $totalPedido += $importeCompra;

                $articulosValidos[] = [
                    'art_id' => $articuloData['art_id'],
                    'clave' => $articulo->clave,
                    'descripcion' => $articulo->descripcion,
                    'cantidad' => number_format($cantidad, 4, '.', ''),
                    'unidad' => $articulo->unidadCompra,
                    'precioCompra' => number_format($precioCompra, 6, '.', ''),
                    'importeCompra' => number_format($importeCompra, 2, '.', ''),
                    'orden' => $orden++
                ];
            }

            // 4. Crear Pedido Principal en SICAR
            $pedido = Pedido::create([
                'fecha' => now()->format('Y-m-d'),
                'total' => number_format($totalPedido, 2, '.', ''),
                'monAbr' => null,
                'monTotal' => null,
                'monTipoCambio' => null,
                'img' => $datos['opciones']['img'] ? 1 : 0,
                'caracteristicas' => $datos['opciones']['caracteristicas'] ? 1 : 0,
                'desglosado' => $datos['opciones']['desglosado'] ? 1 : 0,
                'mostrarPrecios' => $datos['opciones']['mostrarPrecios'] ? 1 : 0,
                'mostrarClaveAlterna' => $datos['opciones']['mostrarClaveAlterna'] ? 1 : 0,
                'comentario' => $datos['comentario'] ?? '',
                'status' => 1, // Pedido activo/pendiente
                'usu_id' => 1, // Usuario por defecto de TUNNEL
                'pro_id' => $datos['pro_id']
            ]);

            // 5. Crear Detalles del Pedido
            $detallesCreados = [];
            foreach ($articulosValidos as $detalle) {
                $detalleCreado = DetallePedido::create([
                    'ped_id' => $pedido->ped_id,
                    'art_id' => $detalle['art_id'],
                    'clave' => $detalle['clave'],
                    'descripcion' => $detalle['descripcion'],
                    'cantidad' => $detalle['cantidad'],
                    'unidad' => $detalle['unidad'],
                    'precioCompra' => $detalle['precioCompra'],
                    'importeCompra' => $detalle['importeCompra'],
                    'monPrecioCompra' => null,
                    'monImporteCompra' => null,
                    'orden' => $detalle['orden']
                ]);

                $detallesCreados[] = [
                    'ped_id' => $pedido->ped_id,
                    'art_id' => $detalleCreado->art_id,
                    'clave' => $detalleCreado->clave,
                    'descripcion' => $detalleCreado->descripcion,
                    'cantidad' => $detalleCreado->cantidad,
                    'unidad' => $detalleCreado->unidad,
                    'precioCompra' => $detalleCreado->precioCompra,
                    'importeCompra' => $detalleCreado->importeCompra,
                    'orden' => $detalleCreado->orden
                ];
            }

            DB::commit();

            // 6. Respuesta según especificaciones CUSPI
            return response()->json([
                'success' => true,
                'mensaje' => 'Pedido creado correctamente en SICAR',
                'pedido' => [
                    'ped_id' => $pedido->ped_id,
                    'total' => $pedido->total,
                    'fecha' => $pedido->fecha,
                    'articulos' => count($detallesCreados),
                    'proveedor' => $proveedor->nombre,
                    'detalles' => $detallesCreados
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
}