<?php
// Script worker para ejecutar backup en background con webhook - VERSIÓN UBUNTU
require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Log;

// Configurar log específico para backups
function logBackup($logId, $level, $message, $context = []) {
    $logFile = storage_path('logs/backup_process.log');
    
    // Si el archivo es muy grande (>1MB), reiniciarlo
    if (file_exists($logFile) && filesize($logFile) > 1024 * 1024) {
        file_put_contents($logFile, ''); // Vaciar archivo
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
    $logLine = "[$timestamp] $level: [$logId] $message$contextStr\n";
    
    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

// Arrancar Laravel bootstrap
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Obtener parámetros de línea de comandos
$logId = $argv[1] ?? 'backup_' . uniqid();
$webhookUrl = $argv[2] ?? null;

if (!$webhookUrl) {
    echo "Error: webhook URL requerida\n";
    exit(1);
}

try {
    logBackup($logId, 'INFO', 'BACKUP WEBHOOK INICIADO (UBUNTU)', [
        'webhook_url' => $webhookUrl,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

    // Aumentar límites para backups grandes
    ini_set('memory_limit', '2G');
    ini_set('max_execution_time', 600); // 10 minutos
    set_time_limit(600);

    $startTime = microtime(true);
    $timestamp = date('Y-m-d_H-i-s');

    // 0. Limpieza automática: mantener solo 2 backups más recientes
    $backupDir = storage_path('app/backups');
    if (is_dir($backupDir)) {
        $files = glob($backupDir . DIRECTORY_SEPARATOR . 'backup_*');
        
        // Filtrar archivos válidos y ordenar por fecha de modificación
        $validFiles = [];
        foreach ($files as $file) {
            if (is_file($file) && preg_match('/backup_[a-f0-9]+_.*\.(gz|tar\.gz)$/', basename($file))) {
                $validFiles[] = [
                    'path' => $file,
                    'name' => basename($file),
                    'time' => filemtime($file),
                    'size' => filesize($file)
                ];
            }
        }
        
        // Ordenar por fecha (más reciente primero)
        usort($validFiles, function($a, $b) {
            return $b['time'] - $a['time'];
        });
        
        // Eliminar archivos antiguos (mantener solo 2)
        $maxBackups = 2;
        if (count($validFiles) >= $maxBackups) {
            $filesToDelete = array_slice($validFiles, $maxBackups - 1);
            foreach ($filesToDelete as $fileInfo) {
                if (unlink($fileInfo['path'])) {
                    Log::info("[$logId] ARCHIVO ANTIGUO ELIMINADO", [
                        'filename' => $fileInfo['name'],
                        'size_mb' => round($fileInfo['size'] / (1024 * 1024), 2)
                    ]);
                }
            }
        }
    }

    // 1. Configuración de la conexión
    $host = config('database.connections.mysql.host');
    $port = config('database.connections.mysql.port');
    $database = config('database.connections.mysql.database');
    $username = config('database.connections.mysql.username');
    $password = config('database.connections.mysql.password');

    Log::info("[$logId] CONFIGURACIÓN LEÍDA (UBUNTU)", [
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => $username,
        'password_set' => !empty($password)
    ]);

    // 2. Crear directorio si no existe
    $filename = "sicar_backup_{$timestamp}.sql";
    $backupDir = storage_path('app/backups');

    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
        Log::info("[$logId] DIRECTORIO CREADO", ['path' => $backupDir]);
    } else {
        Log::info("[$logId] DIRECTORIO EXISTE", ['path' => $backupDir]);
    }

    $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;

    Log::info("[$logId] ARCHIVO DESTINO", ['filepath' => $filepath]);

    // 3. Ejecutar mysqldump - UBUNTU VERSION
    $mysqldumpPath = env('MYSQLDUMP_PATH', "/usr/bin/mysqldump"); // Ruta estándar Ubuntu
    
    $command = sprintf(
        '%s --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers %s > "%s" 2>/dev/null',
        $mysqldumpPath,
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($username),
        escapeshellarg($password),
        escapeshellarg($database),
        $filepath
    );

    Log::info("[$logId] COMANDO MYSQLDUMP (UBUNTU)", [
        'platform' => 'ubuntu',
        'command' => preg_replace('/--password=\S+/', '--password=***', $command)
    ]);

    $dumpStartTime = microtime(true);
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    $dumpEndTime = microtime(true);

    if ($returnCode !== 0) {
        throw new Exception("mysqldump failed with return code: $returnCode");
    }

    Log::info("[$logId] MYSQLDUMP EJECUTADO", [
        'execution_time' => number_format($dumpEndTime - $dumpStartTime, 2) . 's',
        'return_code' => $returnCode
    ]);

    // 4. Verificar que el archivo fue creado
    if (!file_exists($filepath)) {
        throw new Exception("Error: archivo backup no fue creado. Filepath: {$filepath}");
    }

    $filesize = filesize($filepath);
    if ($filesize === 0) {
        throw new Exception("Error: archivo backup está vacío. Filepath: {$filepath}");
    }

    Log::info("[$logId] BACKUP SQL CREADO", [
        'filesize' => $filesize,
        'filesize_mb' => number_format($filesize / (1024 * 1024), 2)
    ]);

    // 5. Comprimir el archivo usando gzip nativo de Ubuntu
    Log::info("[$logId] INICIANDO COMPRESIÓN GZIP", ['method' => 'gzip_native_ubuntu']);
    
    $compressStartTime = microtime(true);
    $compressedFile = $filepath . '.gz';
    
    // Usar comando gzip nativo de Ubuntu (más eficiente)
    $gzipCommand = sprintf('gzip -9 "%s"', $filepath); // -9 = máxima compresión
    $gzipOutput = [];
    $gzipReturn = 0;
    exec($gzipCommand, $gzipOutput, $gzipReturn);
    
    $compressEndTime = microtime(true);
    
    if ($gzipReturn !== 0) {
        throw new Exception("gzip compression failed with return code: $gzipReturn");
    }
    
    Log::info("[$logId] COMPRESIÓN GZIP COMPLETADA", [
        'execution_time' => number_format($compressEndTime - $compressStartTime, 2) . 's',
        'compressed_file' => $compressedFile
    ]);

    // 6. Verificar compresión y obtener estadísticas
    if (!file_exists($compressedFile)) {
        throw new Exception("Error: archivo comprimido no fue creado");
    }

    $originalSize = $filesize;
    $compressedSize = filesize($compressedFile);
    $compressionRatio = round(($compressedSize / $originalSize) * 100, 1);

    Log::info("[$logId] ARCHIVO COMPRIMIDO", [
        'original_size_mb' => number_format($originalSize / (1024 * 1024), 2),
        'compressed_size_mb' => number_format($compressedSize / (1024 * 1024), 2),
        'compression_ratio' => $compressionRatio . '%'
    ]);

    // 7. Generar URL de descarga y configurar expiración
    $baseUrl = 'https://tunnelcuspi.site'; // URL de producción fija
    $downloadFilename = $logId . '_' . basename($compressedFile);
    $downloadPath = $backupDir . DIRECTORY_SEPARATOR . $downloadFilename;
    
    // Renombrar archivo con job_id para tracking
    if (rename($compressedFile, $downloadPath)) {
        $compressedFile = $downloadPath;
    }
    
    $downloadUrl = $baseUrl . '/api/backup/download/' . $downloadFilename;
    $expiresAt = date('Y-m-d H:i:s', strtotime('+6 hours'));

    $totalTime = microtime(true) - $startTime;

    logBackup($logId, 'INFO', 'BACKUP COMPLETADO (UBUNTU)', [
        'total_time' => number_format($totalTime, 2) . 's',
        'download_url' => $downloadUrl,
        'expires_at' => $expiresAt,
        'success' => true
    ]);

    // 8. Enviar webhook notification
    $webhookData = [
        'job_id' => $logId,
        'status' => 'completed',
        'filename' => basename($compressedFile),
        'filesize_mb' => number_format($compressedSize / (1024 * 1024), 2),
        'download_url' => $downloadUrl,
        'expires_at' => $expiresAt,
        'total_time_seconds' => round($totalTime),
        'timestamp' => date('Y-m-d H:i:s')
    ];

    // Enviar webhook usando Laravel HTTP client
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'TunnelCUSPI-Webhook/1.0-Ubuntu'
            ])
            ->post($webhookUrl, $webhookData);
        
        $webhookHttpCode = $response->status();
        $webhookResponse = $response->body();
    } catch (Exception $webhookEx) {
        $webhookHttpCode = 0;
        $webhookResponse = 'Exception: ' . $webhookEx->getMessage();
    }

    Log::info("[$logId] WEBHOOK ENVIADO (UBUNTU)", [
        'webhook_url' => $webhookUrl,
        'http_code' => $webhookHttpCode,
        'response' => $webhookResponse ? substr($webhookResponse, 0, 200) : 'empty'
    ]);

    echo "Backup completado exitosamente (Ubuntu). Job ID: $logId\n";

} catch (Exception $e) {
    Log::error("[$logId] ERROR EN BACKUP WEBHOOK (UBUNTU)", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    // Enviar webhook de error
    if ($webhookUrl) {
        $errorData = [
            'job_id' => $logId,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        try {
            \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($webhookUrl, $errorData);
        } catch (Exception $webhookEx) {
            Log::error("[$logId] ERROR ENVIANDO WEBHOOK DE ERROR (UBUNTU)", [
                'webhook_url' => $webhookUrl,
                'error' => $webhookEx->getMessage()
            ]);
        }
    }

    echo "Error en backup (Ubuntu): " . $e->getMessage() . "\n";
    exit(1);
}