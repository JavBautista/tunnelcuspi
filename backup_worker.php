<?php
// Script worker para ejecutar backup en background con webhook
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
    logBackup($logId, 'INFO', 'BACKUP WEBHOOK INICIADO', [
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

    Log::info("[$logId] CONFIGURACIÓN LEÍDA", [
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

    // 3. Crear archivo de configuración temporal para mysqldump
    $configFile = storage_path('app/backups/mysql_temp.cnf');
    $configContent = "[client]\n";
    $configContent .= "host = {$host}\n";
    $configContent .= "port = {$port}\n";
    $configContent .= "user = {$username}\n";
    if (!empty($password)) {
        $configContent .= "password = {$password}\n";
    }

    file_put_contents($configFile, $configContent);

    // 4. Ejecutar mysqldump
    $mysqldumpPath = "C:\\Program Files (x86)\\SICAR-8C460\\MySQL\\MySQL Server 5.5\\bin\\mysqldump.exe";
    
    $command = sprintf(
        '"%s" --defaults-file="%s" --single-transaction --routines --triggers %s > "%s" 2>nul',
        $mysqldumpPath,
        $configFile,
        $database,
        $filepath
    );

    Log::info("[$logId] COMANDO MYSQLDUMP", [
        'platform' => 'windows',
        'command' => $command
    ]);

    $dumpStartTime = microtime(true);
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    $dumpEndTime = microtime(true);

    Log::info("[$logId] MYSQLDUMP EJECUTADO", [
        'execution_time' => number_format($dumpEndTime - $dumpStartTime, 2) . 's',
        'result_length' => count($output),
        'result_preview' => count($output) > 0 ? substr(implode("\n", $output), 0, 200) : null
    ]);

    // 5. Verificar que el archivo fue creado
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

    // 6. Limpiar archivo de configuración
    if (file_exists($configFile)) {
        unlink($configFile);
        Log::info("[$logId] CONFIG FILE LIMPIADO", ['config_file' => $configFile]);
    }

    // 7. Comprimir el archivo usando PHP nativo (más confiable en Windows)
    Log::info("[$logId] INICIANDO COMPRESIÓN PHP", ['method' => 'gzip_native']);
    
    $compressStartTime = microtime(true);
    $compressedFile = $filepath . '.gz';
    
    // Usar gzopen para mejor control de memoria
    $source = fopen($filepath, 'rb');
    $compressed = gzopen($compressedFile, 'wb9'); // Máxima compresión
    
    if (!$source || !$compressed) {
        throw new Exception("Error abriendo archivos para compresión");
    }
    
    // Leer y comprimir en chunks de 1MB para evitar problemas de memoria
    while (!feof($source)) {
        $chunk = fread($source, 1024 * 1024);
        gzwrite($compressed, $chunk);
    }
    
    fclose($source);
    gzclose($compressed);
    
    $compressEndTime = microtime(true);
    
    Log::info("[$logId] COMPRESIÓN PHP COMPLETADA", [
        'execution_time' => number_format($compressEndTime - $compressStartTime, 2) . 's',
        'compressed_file' => $compressedFile
    ]);

    // 8. Verificar compresión y obtener estadísticas
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

    // 9. Eliminar archivo original SQL (mantener solo el comprimido)
    if (file_exists($filepath)) {
        unlink($filepath);
    }

    // 10. Generar URL de descarga y configurar expiración
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

    logBackup($logId, 'INFO', 'BACKUP COMPLETADO', [
        'total_time' => number_format($totalTime, 2) . 's',
        'download_url' => $downloadUrl,
        'expires_at' => $expiresAt,
        'success' => true
    ]);

    // 11. Enviar webhook notification con campos que espera CUSPI
    $webhookData = [
        'job_id' => $logId,
        'status' => 'completed',
        'filename' => basename($compressedFile), // AGREGADO: nombre del archivo
        'filesize_mb' => number_format($compressedSize / (1024 * 1024), 2), // CORREGIDO: filesize_mb
        'download_url' => $downloadUrl,
        'expires_at' => $expiresAt,
        'total_time_seconds' => round($totalTime),
        'timestamp' => date('Y-m-d H:i:s')
    ];

    // Enviar webhook usando Laravel HTTP client simplificado
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'TunnelCUSPI-Webhook/1.0'
            ])
            ->withOptions([
                'verify' => false // Deshabilitar verificación SSL para compatibilidad
            ])
            ->post($webhookUrl, $webhookData);
        
        $webhookHttpCode = $response->status();
        $webhookResponse = $response->body();
    } catch (Exception $webhookEx) {
        $webhookHttpCode = 0;
        $webhookResponse = 'Exception: ' . $webhookEx->getMessage();
    }

    Log::info("[$logId] WEBHOOK ENVIADO", [
        'webhook_url' => $webhookUrl,
        'http_code' => $webhookHttpCode,
        'response' => $webhookResponse ? substr($webhookResponse, 0, 200) : 'empty'
    ]);

    echo "Backup completado exitosamente. Job ID: $logId\n";

} catch (Exception $e) {
    Log::error("[$logId] ERROR EN BACKUP WEBHOOK", [
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
            Log::error("[$logId] ERROR ENVIANDO WEBHOOK DE ERROR", [
                'webhook_url' => $webhookUrl,
                'error' => $webhookEx->getMessage()
            ]);
        }
    }

    echo "Error en backup: " . $e->getMessage() . "\n";
    exit(1);
}