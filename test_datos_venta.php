#!/usr/bin/env php
<?php
// Script tinker para consultar datos para prueba de venta

// Cliente activo
echo "CLI: ";
$cliente = DB::table('cliente')->where('status', 1)->first();
echo "cli_id={$cliente->cli_id}, nombre={$cliente->nombre}\n";

// Artículo activo con existencia
echo "\nART: ";
$articulo = DB::table('articulo')->where('status', 1)->first();
echo "art_id={$articulo->art_id}, clave={$articulo->clave}, desc={$articulo->descripcion}, precio1={$articulo->precio1}, costo={$articulo->precioCompra}\n";

// Existencia
echo "\nEXI: ";
$existencia = DB::table('existencia')->where('art_id', $articulo->art_id)->where('suc_id', 1)->first();
echo "art_id={$existencia->art_id}, suc_id={$existencia->suc_id}, existencia={$existencia->existencia}\n";

// Impuesto
echo "\nIMP: ";
$impuesto = DB::table('impuesto')->where('status', 1)->first();
echo "imp_id={$impuesto->imp_id}, nombre={$impuesto->nombre}, valor={$impuesto->valor}\n";

// Tipo de pago
echo "\nTPA: ";
$tipopago = DB::table('tipopago')->first();
echo "tpa_id={$tipopago->tpa_id}, nombre={$tipopago->nombre}\n";

// Usuario
echo "\nUSU: ";
$usuario = DB::table('usuario')->where('status', 1)->first();
echo "usu_id={$usuario->usu_id}, nombre={$usuario->nombre}\n";
