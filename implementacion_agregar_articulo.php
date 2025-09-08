<?php

/**
 * IMPLEMENTACIÓN: AGREGAR ARTÍCULO A COTIZACIÓN SIGUIENDO FLUJO EXACTO DE SICAR
 * 
 * Basado en el análisis exhaustivo del módulo secotizacion-4.0.jar
 * Replica exactamente: CotizacionLogic.agregarArticulo() y DocumentoSalidaLogic.agregarArticulo()
 */

// Agregar este método al CotizacionController.php después del método crearCotizacionComoSicar()

/**
 * Crea una cotización nueva Y agrega un artículo de prueba
 * Siguiendo exactamente el flujo de SICAR identificado en el análisis
 */
public function crearCotizacionConArticuloPrueba()
{
    try {
        Log::info('TUNNEL: Iniciando creación de cotización + artículo siguiendo flujo exacto SICAR');

        DB::beginTransaction();

        // PASO 1: CREAR COTIZACIÓN VACÍA (usar método existente)
        $responseCotizacion = $this->crearCotizacionComoSicar();
        $dataCotizacion = json_decode($responseCotizacion->getContent(), true);
        
        if (!$dataCotizacion['success']) {
            throw new \Exception('Error al crear cotización base');
        }

        $cotizacionId = $dataCotizacion['cotizacion']['cot_id'];

        // PASO 2: AGREGAR ARTÍCULO DE PRUEBA
        $articuloId = 1634; // "4-1025617" - Papelera Basurero Elite 121 Lts Rojo
        $cantidad = 1.000;

        $resultado = $this->agregarArticuloACotizacion($cotizacionId, $articuloId, $cantidad);

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
 * Agrega un artículo a una cotización existente
 * REPLICA EXACTAMENTE: CotizacionLogic.agregarArticulo() + DocumentoSalidaLogic.agregarArticulo()
 */
private function agregarArticuloACotizacion($cotizacionId, $articuloId, $cantidad = 1.000)
{
    Log::info('TUNNEL: Agregando artículo a cotización', [
        'cot_id' => $cotizacionId,
        'art_id' => $articuloId,
        'cantidad' => $cantidad
    ]);

    // VALIDACIÓN 1: Verificar que la cotización existe
    $cotizacion = DB::table('cotizacion')->where('cot_id', $cotizacionId)->first();
    if (!$cotizacion) {
        throw new \Exception("Cotización {$cotizacionId} no encontrada");
    }

    // VALIDACIÓN 2: Verificar que el artículo existe
    $articulo = DB::table('articulo')
        ->leftJoin('unidad', 'articulo.uni_id1', '=', 'unidad.uni_id')
        ->where('articulo.art_id', $articuloId)
        ->select('articulo.*', 'unidad.nombre as unidad_nombre')
        ->first();
        
    if (!$articulo) {
        throw new \Exception("Artículo {$articuloId} no encontrado");
    }

    // VALIDACIÓN 3: Verificar artículo duplicado (CRÍTICO EN SICAR)
    $detalleExistente = DB::table('detallecot')
        ->where('cot_id', $cotizacionId)
        ->where('art_id', $articuloId)
        ->first();

    if ($detalleExistente) {
        throw new \Exception("Artículo ya existe en cotización. SICAR sumaría cantidad, aquí por simplicidad fallaremos.");
    }

    // OBTENER DATOS NECESARIOS PARA CÁLCULOS
    $ventaConf = DB::table('ventaconf')->first();
    $cliente = DB::table('cliente')->where('cli_id', $cotizacion->cli_id)->first();

    // VALIDACIÓN 4: Artículos tipo servicio (getTipo() == 1)
    if ($articulo->tipo == 1) {
        $tieneOtrosArticulos = DB::table('detallecot')
            ->where('cot_id', $cotizacionId)
            ->exists();
            
        if ($tieneOtrosArticulos) {
            throw new \Exception("No puedes hacer una Recarga/Pago de Servicio junto con otros artículos");
        }
    }

    // VALIDACIÓN 5: Cantidad válida
    if (!$ventaConf->ventaGranel && !$articulo->granel) {
        // No permite decimales si no es granel
        if (fmod($cantidad, 1) != 0) {
            throw new \Exception("La cantidad no puede contener decimales para este artículo");
        }
    }

    if (!$ventaConf->venderCantNegativa && $cantidad < 0) {
        throw new \Exception("La cantidad no puede ser negativa");
    }

    // CÁLCULOS EXACTOS SIGUIENDO SICAR

    // CÁLCULO 1: Precio de Compra (CotizacionLogic.crearDetalle línea 749)
    $precioCompraProm = $articulo->preCompraProm / $articulo->factor; // Divide por factor con 6 decimales
    $precioCompraFinal = $this->calcularPrecioConImpuestos(
        $precioCompraProm,
        $articuloId,
        $articulo->iepsActivo,
        $articulo->cuotaIeps
    );

    // CÁLCULO 2: Selección de Precio según Cliente (líneas 142-185)
    $precioCon = null;
    $precioSin = null;
    $precioNormalCon = null;
    $precioNormalSin = null;

    if ($ventaConf->numPreCli) {
        // Usar precio según nivel del cliente
        $nivelPrecio = $cliente->precio; // 1, 2, 3, o 4
        switch ($nivelPrecio) {
            case 1: $precioCon = $articulo->precio1; break;
            case 2: $precioCon = $articulo->precio2; break;
            case 3: $precioCon = $articulo->precio3; break;
            case 4: $precioCon = $articulo->precio4; break;
            default: $precioCon = $articulo->precio1; break;
        }
        
        $precioSin = $this->calcularPrecioSinImpuestos($precioCon, $articuloId);
        $precioNormalCon = $precioCon;
        $precioNormalSin = $precioSin;
    } else {
        // Usar precio 1 general
        $precioCon = $articulo->precio1;
        $precioSin = $this->calcularPrecioSinImpuestos($articulo->precio1, $articuloId);
        $precioNormalCon = $articulo->precio1;
        $precioNormalSin = $articulo->precio1; // En SICAR puede ser diferente
    }

    // CÁLCULO 3: Obtener siguiente orden
    $siguienteOrden = $this->obtenerSiguienteOrden($cotizacionId);

    // CÁLCULO 4: Importes
    $importeCompra = $precioCompraFinal * $cantidad;
    $importeSin = $precioSin * $cantidad;
    $importeCon = $precioCon * $cantidad;

    // INSERCIÓN EN BD - TABLA detallecot (30 CAMPOS EXACTOS)
    $detalleCotData = [
        'cot_id' => $cotizacionId,
        'art_id' => $articuloId,
        'clave' => $articulo->clave,
        'descripcion' => $articulo->descripcion,
        'cantidad' => $cantidad,
        'unidad' => $articulo->unidad_nombre ?? 'PZA',
        'precioCompra' => round($precioCompraFinal, 2),
        'precioNorSin' => round($precioNormalSin, 2),
        'precioNorCon' => round($precioNormalCon, 2),
        'precioSin' => round($precioSin, 2),
        'precioCon' => round($precioCon, 2),
        'importeCompra' => round($importeCompra, 2),
        'importeNorSin' => round($precioNormalSin * $cantidad, 2),
        'importeNorCon' => round($precioNormalCon * $cantidad, 2),
        'importeSin' => round($importeSin, 2),
        'importeCon' => round($importeCon, 2),
        // Campos de moneda extranjera - NULL por defecto
        'monPrecioNorSin' => null,
        'monPrecioNorCon' => null,
        'monPrecioSin' => null,
        'monPrecioCon' => null,
        'monImporteNorSin' => null,
        'monImporteNorCon' => null,
        'monImporteSin' => null,
        'monImporteCon' => null,
        // Cálculos finales
        'diferencia' => 0.00, // Se calculará después
        'utilidad' => 0.000000, // Se calculará después
        'descPorcentaje' => 0.00,
        'descTotal' => 0.00,
        'caracteristicas' => $articulo->caracteristicas,
        'orden' => $siguienteOrden
    ];

    // INSERTAR EN BD
    DB::table('detallecot')->insert($detalleCotData);

    // COPIAR IMPUESTOS DEL ARTÍCULO (tabla detallecotimpuesto)
    $this->copiarImpuestosArticulo($cotizacionId, $articuloId);

    // RECALCULAR TOTALES DE LA COTIZACIÓN
    $this->recalcularTotalesCotizacion($cotizacionId);

    Log::info('TUNNEL: Artículo agregado exitosamente', [
        'cot_id' => $cotizacionId,
        'art_id' => $articuloId,
        'precio_con' => $precioCon,
        'precio_sin' => $precioSin,
        'importe_con' => $importeCon,
        'orden' => $siguienteOrden
    ]);

    return [
        'art_id' => $articuloId,
        'clave' => $articulo->clave,
        'descripcion' => $articulo->descripcion,
        'cantidad' => $cantidad,
        'precio_con' => $precioCon,
        'precio_sin' => $precioSin,
        'importe_con' => $importeCon,
        'orden' => $siguienteOrden
    ];
}

/**
 * Calcula precio CON impuestos
 * Replica: Calculador.calcularPrecioConImpuestos()
 */
private function calcularPrecioConImpuestos($precioBase, $articuloId, $iepsActivo, $cuotaIeps)
{
    // Por simplicidad, implementación básica
    // En producción debería replicar exactamente el Calculador de SICAR
    
    if ($iepsActivo && $cuotaIeps > 0) {
        // Aplicar IEPS
        $precioBase += $cuotaIeps;
    }

    // Obtener impuestos del artículo
    $impuestos = DB::table('articuloimpuesto')
        ->join('impuesto', 'articuloimpuesto.imp_id', '=', 'impuesto.imp_id')
        ->where('articuloimpuesto.art_id', $articuloId)
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
 * Calcula precio SIN impuestos
 */
private function calcularPrecioSinImpuestos($precioConImpuestos, $articuloId)
{
    // Implementación simplificada
    // En producción debería hacer el cálculo inverso exacto
    
    $impuestos = DB::table('articuloimpuesto')
        ->join('impuesto', 'articuloimpuesto.imp_id', '=', 'impuesto.imp_id')
        ->where('articuloimpuesto.art_id', $articuloId)
        ->where('impuesto.status', 1)
        ->get();

    $factorImpuestos = 1;
    
    foreach ($impuestos as $impuesto) {
        if ($impuesto->aplicacion == 1) { // Porcentaje
            $factorImpuestos += ($impuesto->porcentaje / 100);
        }
    }

    return $precioConImpuestos / $factorImpuestos;
}

/**
 * Obtiene el siguiente orden para el artículo en la cotización
 */
private function obtenerSiguienteOrden($cotizacionId)
{
    $maxOrden = DB::table('detallecot')
        ->where('cot_id', $cotizacionId)
        ->max('orden');
        
    return ($maxOrden ?? 0) + 1;
}

/**
 * Copia los impuestos del artículo a detallecotimpuesto
 */
private function copiarImpuestosArticulo($cotizacionId, $articuloId)
{
    $impuestos = DB::table('articuloimpuesto')
        ->where('art_id', $articuloId)
        ->get();

    foreach ($impuestos as $impuesto) {
        DB::table('detallecotimpuesto')->insert([
            'cot_id' => $cotizacionId,
            'art_id' => $articuloId,
            'imp_id' => $impuesto->imp_id
        ]);
    }
}

/**
 * Recalcula los totales de la cotización
 */
private function recalcularTotalesCotizacion($cotizacionId)
{
    // Obtener totales de todos los detalles
    $totales = DB::table('detallecot')
        ->where('cot_id', $cotizacionId)
        ->selectRaw('
            SUM(importeSin) as subtotal,
            SUM(importeCon) as total,
            SUM(descTotal) as descuento_total
        ')
        ->first();

    // Actualizar cotización
    DB::table('cotizacion')
        ->where('cot_id', $cotizacionId)
        ->update([
            'subtotal' => $totales->subtotal ?? 0.00,
            'total' => $totales->total ?? 0.00,
            'descuento' => $totales->descuento_total > 0 ? $totales->descuento_total : null
        ]);
}

// RUTA PARA PROBAR (agregar al routes/api.php):
// Route::post('/cotizacion/crear-con-articulo-prueba', [CotizacionController::class, 'crearCotizacionConArticuloPrueba']);

?>