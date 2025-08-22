<?php
// VERSIÓN DE PRUEBA - Solo simula el backup y envía webhook
require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Log;

// Arrancar Laravel bootstrap
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Obtener parámetros
$logId = $argv[1] ?? 'backup_' . uniqid();
$webhookUrl = $argv[2] ?? null;

if (!$webhookUrl) {
    echo "Error: webhook URL requerida\n";
    exit(1);
}

try {
    Log::info("[$logId] TEST BACKUP WEBHOOK INICIADO", [
        'webhook_url' => $webhookUrl,
        'timestamp' => date('Y-m-d H:i:s'),
        'mode' => 'TEST - Sin backup real'
    ]);

    // Simular delay corto de 5 segundos
    Log::info("[$logId] SIMULANDO PROCESO BACKUP", [
        'message' => 'Esperando 5 segundos para simular proceso'
    ]);
    
    sleep(5); // Solo 5 segundos de espera
    
    // Simular datos del backup
    $downloadUrl = 'http://cuspi.test/api/backup/download/' . $logId . '_test_backup.sql.gz';
    $expiresAt = date('Y-m-d H:i:s', strtotime('+6 hours'));
    
    Log::info("[$logId] TEST BACKUP COMPLETADO", [
        'download_url' => $downloadUrl,
        'expires_at' => $expiresAt,
        'file_size_mb' => '378.73',
        'mode' => 'TEST',
        'success' => true
    ]);

    // Enviar webhook con campos que espera CUSPI
    $webhookData = [
        'job_id' => $logId,
        'status' => 'completed',
        'filename' => $logId . '_test_backup.sql.gz', // AGREGADO: filename
        'filesize_mb' => '378.73', // CORREGIDO: filesize_mb (sin guión bajo extra)
        'download_url' => $downloadUrl,
        'expires_at' => $expiresAt,
        'total_time_seconds' => 5,
        'timestamp' => date('Y-m-d H:i:s')
        // 'mode' => 'TEST' // REMOVIDO: puede confundir a CUSPI
    ];

    // Enviar webhook usando Laravel HTTP client
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'TunnelCUSPI-Webhook/1.0'
            ])
            ->withOptions([
                'verify' => false
            ])
            ->post($webhookUrl, $webhookData);
        
        $webhookHttpCode = $response->status();
        $webhookResponse = $response->body();
    } catch (Exception $webhookEx) {
        $webhookHttpCode = 0;
        $webhookResponse = 'Exception: ' . $webhookEx->getMessage();
    }

    Log::info("[$logId] TEST WEBHOOK ENVIADO", [
        'webhook_url' => $webhookUrl,
        'http_code' => $webhookHttpCode,
        'response' => $webhookResponse ? substr($webhookResponse, 0, 200) : 'empty'
    ]);

    echo "Test backup completado. Job ID: $logId\n";

} catch (Exception $e) {
    Log::error("[$logId] ERROR EN TEST BACKUP WEBHOOK", [
        'error' => $e->getMessage()
    ]);

    // Enviar webhook de error
    if ($webhookUrl) {
        $errorData = [
            'job_id' => $logId,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s'),
            'mode' => 'TEST'
        ];

        try {
            \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions(['verify' => false])
                ->post($webhookUrl, $errorData);
        } catch (Exception $webhookEx) {
            Log::error("[$logId] ERROR ENVIANDO WEBHOOK DE ERROR", [
                'error' => $webhookEx->getMessage()
            ]);
        }
    }

    echo "Error en test backup: " . $e->getMessage() . "\n";
    exit(1);
}