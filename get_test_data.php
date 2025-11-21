<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DATOS PARA PRUEBA DE VENTA ===\n\n";

$cli = DB::table('cliente')->where('status', 1)->first();
$art = DB::table('articulo')->where('status', 1)->first();
$exi = DB::table('existencia')->where('art_id', $art->art_id)->where('suc_id', 1)->first();
$imp = DB::table('impuesto')->where('status', 1)->first();
$tpa = DB::table('tipopago')->first();
$usu = DB::table('usuario')->where('status', 1)->first();

echo "Cliente: cli_id={$cli->cli_id} ({$cli->nombre})\n";
echo "Artículo: art_id={$art->art_id}, clave={$art->clave}, precio1={$art->precio1}, costo={$art->precioCompra}\n";
echo "Existencia: art_id={$exi->art_id}, existencia={$exi->existencia}\n";
echo "Impuesto: imp_id={$imp->imp_id}, valor={$imp->valor}%\n";
echo "TipoPago: tpa_id={$tpa->tpa_id} ({$tpa->nombre})\n";
echo "Usuario: usu_id={$usu->usu_id}\n";

// Calcular valores
$cantidad = 2;
$precioSin = floatval($art->precio1);
$tasaImp = floatval($imp->valor);
$importeSin = $precioSin * $cantidad;
$importeImp = $importeSin * ($tasaImp / 100);
$precioCon = $precioSin * (1 + $tasaImp / 100);
$importeCon = $importeSin + $importeImp;

echo "\n=== CÁLCULOS ===\n";
echo "Cantidad: {$cantidad}\n";
echo "Precio sin IVA: \${$precioSin}\n";
echo "Importe sin IVA: \${$importeSin}\n";
echo "IVA ({$tasaImp}%): \${$importeImp}\n";
echo "Precio con IVA: \${$precioCon}\n";
echo "Total: \${$importeCon}\n";

echo "\n=== JSON PARA CURL ===\n";
$json = [
    'venta' => [
        'fecha' => date('Y-m-d H:i:s'),
        'subtotal0' => $importeSin,
        'subtotal' => $importeSin,
        'descuento' => 0.00,
        'total' => $importeCon,
        'cambio' => 0.00,
        'comentario' => 'Venta de prueba desde TUNNEL',
        'status' => 1,
        'cli_id' => $cli->cli_id,
        'usu_id' => $usu->usu_id,
        'suc_id' => 1,
        'caj_id' => 1,
        'mon_id' => 1,
        'vnd_id' => null,
        'tipoCambio' => 1.000000
    ],
    'detalles' => [[
        'art_id' => $art->art_id,
        'clave' => $art->clave,
        'descripcion' => $art->descripcion,
        'cantidad' => $cantidad,
        'unidad' => 'PZA',
        'precioSin' => $precioSin,
        'precioCon' => $precioCon,
        'importeSin' => $importeSin,
        'importeCon' => $importeCon,
        'descPorcentaje' => 0.00,
        'descTotal' => 0.00,
        'precioCompra' => floatval($art->precioCompra),
        'orden' => 1
    ]],
    'detallesImpuestos' => [[
        'art_id' => $art->art_id,
        'imp_id' => $imp->imp_id,
        'base' => $importeSin,
        'tasa' => $tasaImp,
        'importe' => $importeImp
    ]],
    'impuestos' => [[
        'imp_id' => $imp->imp_id,
        'base' => $importeSin,
        'importe' => $importeImp
    ]],
    'formasPago' => [[
        'tpa_id' => $tpa->tpa_id,
        'importe' => $importeCon
    ]]
];

echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
