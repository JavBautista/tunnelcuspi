<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\TunnelController;
use App\Http\Controllers\Api\CotizacionController;
use App\Http\Controllers\Api\ArticuloController;
use App\Http\Controllers\Api\PedidoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::middleware('validate.apikey')->group(function() {

    Route::post('/existencia', [TunnelController::class, 'existencia']);
    
    Route::post('/cotizaciones/crear', [CotizacionController::class, 'crear']);
    
    //probando crear cotizacion como sicar
    Route::post('/cotizaciones/crear-sicar', [CotizacionController::class, 'crearCotizacionComoSicar']);
    
    Route::post('/cotizaciones/crear-sicar-vacio', [CotizacionController::class, 'crearCotizacionVacia']);
    
    Route::post('/cotizaciones/agregar-articulo', [CotizacionController::class, 'agregarArticuloACotizacion']);
    
    Route::post('/articulo/asignar-proveedor', [ArticuloController::class, 'asignarProveedor']);
    
    Route::post('/articulo/asignar-proveedor-masivo', [ArticuloController::class, 'asignarProveedorMasivo']);
    
    Route::post('/pedidos/crear', [PedidoController::class, 'crear']);
    
    Route::get('/backup/logs', function (Request $request) {
        try {
            $logFile = storage_path('logs/laravel.log');
            if (!file_exists($logFile)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Log file not found'
                ], 404);
            }
            
            // Obtener las últimas líneas del log
            $lines = (int) $request->query('lines', 50);
            $command = "tail -n {$lines} " . escapeshellarg($logFile);
            $output = shell_exec($command);
            
            // Filtrar solo logs de backup si se especifica
            $filter = $request->query('filter');
            if ($filter) {
                $outputLines = explode("\n", $output);
                $filteredLines = array_filter($outputLines, function($line) use ($filter) {
                    return strpos($line, $filter) !== false;
                });
                $output = implode("\n", $filteredLines);
            }
            
            return response()->json([
                'ok' => true,
                'logs' => $output,
                'lines_requested' => $lines,
                'filter' => $filter,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    });
    
    Route::get('/backup/download/{filename}', function (Request $request, $filename) {
        try {
            // Validar formato del filename (debe ser: backup_xxxxx_archivo.ext)
            if (!preg_match('/^backup_[a-f0-9]+_.*\.(gz|tar\.gz)$/', $filename)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Filename format invalid'
                ], 400);
            }
            
            $backupDir = storage_path('app/backups');
            $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;
            
            // Verificar que el archivo existe
            if (!file_exists($filepath)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'File not found or expired'
                ], 404);
            }
            
            // Verificar tamaño del archivo
            $filesize = filesize($filepath);
            if ($filesize === 0) {
                return response()->json([
                    'ok' => false,
                    'error' => 'File is empty'
                ], 500);
            }
            
            Log::info("BACKUP DOWNLOAD INICIADO", [
                'filename' => $filename,
                'filesize_mb' => round($filesize / (1024 * 1024), 2),
                'client_ip' => $request->ip()
            ]);
            
            // Headers para descarga de archivo
            $headers = [
                'Content-Type' => 'application/gzip',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => $filesize,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ];
            
            // Retornar archivo para descarga
            return response()->file($filepath, $headers);
            
        } catch (Exception $e) {
            Log::error("BACKUP DOWNLOAD FALLÓ", [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'client_ip' => $request->ip()
            ]);
            
            return response()->json([
                'ok' => false,
                'error' => 'Download failed: ' . $e->getMessage()
            ], 500);
        }
    });
    
    Route::delete('/backup/cleanup', function (Request $request) {
        try {
            $backupDir = storage_path('app/backups');
            $cleanupCount = 0;
            $totalSize = 0;
            
            if (!is_dir($backupDir)) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Backup directory does not exist',
                    'cleaned_files' => 0
                ]);
            }
            
            // Buscar archivos de backup (formato: backup_xxxxx_archivo.ext)
            $files = glob($backupDir . DIRECTORY_SEPARATOR . 'backup_*');
            $maxAge = (int)$request->query('max_age_hours', 6); // Default 6 horas
            $cutoffTime = time() - ($maxAge * 3600);
            
            foreach ($files as $filepath) {
                $filename = basename($filepath);
                $fileTime = filemtime($filepath);
                $fileSize = filesize($filepath);
                
                // Eliminar archivos más antiguos que el límite
                if ($fileTime < $cutoffTime) {
                    if (unlink($filepath)) {
                        $cleanupCount++;
                        $totalSize += $fileSize;
                        
                        Log::info("BACKUP FILE CLEANED", [
                            'filename' => $filename,
                            'age_hours' => round((time() - $fileTime) / 3600, 1),
                            'size_mb' => round($fileSize / (1024 * 1024), 2)
                        ]);
                    }
                }
            }
            
            Log::info("BACKUP CLEANUP COMPLETADO", [
                'cleaned_files' => $cleanupCount,
                'total_size_mb' => round($totalSize / (1024 * 1024), 2),
                'max_age_hours' => $maxAge
            ]);
            
            return response()->json([
                'ok' => true,
                'cleaned_files' => $cleanupCount,
                'total_size_mb' => round($totalSize / (1024 * 1024), 2),
                'max_age_hours' => $maxAge
            ]);
            
        } catch (Exception $e) {
            Log::error("BACKUP CLEANUP FALLÓ", [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    });
    
    Route::post('/backup/full', function (Request $request) {
        $logId = uniqid('backup_');
        $webhookUrl = $request->input('webhook_url');
        $isWebhookMode = !empty($webhookUrl);
        
        // Si es webhook, respuesta inmediata
        if ($isWebhookMode) {
            // Detectar información del cliente que solicita
            $clientInfo = [
                'webhook_url' => $webhookUrl,
                'timestamp' => date('Y-m-d H:i:s'),
                'client_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'referer' => $request->header('referer'),
                'origin' => $request->header('origin'),
                'host' => $request->header('host'),
                'x_forwarded_for' => $request->header('x-forwarded-for'),
                'all_headers' => $request->headers->all()
            ];
            
            Log::info("[$logId] BACKUP WEBHOOK SOLICITADO", $clientInfo);
            
            // Lanzar job en background usando script worker con PHP de Laragon
            $backgroundCommand = sprintf(
                '"C:\laragon\bin\php\php-7.4.33-nts-Win32-vc15-x86\php.exe" %s %s %s',
                base_path('backup_worker.php'),
                $logId,
                escapeshellarg($webhookUrl)
            );
            
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // CAMBIAR ENTRE TEST Y REAL AQUÍ
                $useRealBackup = true; // CAMBIAR A false PARA MODO TEST
                
                if ($useRealBackup) {
                    // MODO REAL: Usar launcher.bat para verdadero background
                    $launcherPath = base_path('backup_launcher.bat');
                    $backgroundCommand = sprintf(
                        '"%s" %s %s',
                        $launcherPath,
                        $logId,
                        escapeshellarg($webhookUrl)
                    );
                } else {
                    // MODO TEST: Usar método directo (funciona bien para 5 segundos)
                    $phpPath = 'C:\\laragon\\bin\\php\\php-7.4.33-nts-Win32-vc15-x86\\php.exe';
                    $scriptPath = base_path('backup_worker_test.php');
                    $backgroundCommand = sprintf(
                        '"%s" "%s" %s %s',
                        $phpPath,
                        $scriptPath,
                        $logId,
                        escapeshellarg($webhookUrl)
                    );
                }
            }
            
            // Lanzar proceso en background (mismo método que funcionaba en TEST)
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows: usar popen sin pclose (método que funcionaba en TEST)
                $process = popen($backgroundCommand . ' 2>&1', 'r');
                if ($process) {
                    // NO usar pclose aquí - dejar el proceso corriendo
                    // pclose($process); // COMENTADO para no esperar
                }
            } else {
                // Linux/Unix: usar exec normal
                exec($backgroundCommand);
            }
            
            return response()->json([
                'ok' => true,
                'type' => 'webhook',
                'job_id' => $logId,
                'status' => 'processing',
                'message' => 'Backup job started. You will receive a webhook notification when ready.',
                'webhook_url' => $webhookUrl,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Modo sincrónico (mantener compatibilidad)
        // Aumentar límites para backups grandes
        ini_set('max_execution_time', 600); // 10 minutos
        ini_set('memory_limit', '2G'); // 2GB memoria
        
        $startTime = microtime(true);
        
        // Log inicial
        Log::info("[$logId] BACKUP INICIADO", [
            'platform' => strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'windows' : 'linux',
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ]);
        
        try {
            // 0. Limpieza automática: mantener solo 2 backups más recientes
            $backupDir = storage_path('app/backups');
            if (is_dir($backupDir)) {
                $files = glob($backupDir . DIRECTORY_SEPARATOR . 'backup_*');
                if (count($files) >= 2) {
                    // Ordenar por tiempo de modificación (más antiguos primero)
                    usort($files, function($a, $b) {
                        return filemtime($a) - filemtime($b);
                    });
                    
                    // Eliminar archivos antiguos, mantener solo los 2 más recientes
                    $filesToDelete = array_slice($files, 0, -1); // Mantener 1, eliminar el resto
                    foreach ($filesToDelete as $oldFile) {
                        $filesize = filesize($oldFile);
                        if (unlink($oldFile)) {
                            Log::info("[$logId] ARCHIVO ANTIGUO ELIMINADO", [
                                'filename' => basename($oldFile),
                                'size_mb' => round($filesize / (1024 * 1024), 2)
                            ]);
                        }
                    }
                }
            }
            
            // 1. Configuración universal desde .env
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
            
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "sicar_backup_{$timestamp}.sql";
            $backupDir = storage_path('app/backups');
            
            // 2. Crear directorio si no existe
            if (!file_exists($backupDir)) {
                mkdir($backupDir, 0755, true);
                Log::info("[$logId] DIRECTORIO CREADO", ['path' => $backupDir]);
            } else {
                Log::info("[$logId] DIRECTORIO EXISTE", ['path' => $backupDir]);
            }
            
            $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;
            Log::info("[$logId] ARCHIVO DESTINO", ['filepath' => $filepath]);
            
            // 3. Comando mysqldump universal (Ubuntu + Windows)
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            
            // Comando con redirección directa al archivo (sin cargar en memoria)
            if ($isWindows) {
                // Windows: crear archivo temporal de configuración MySQL
                $configFile = storage_path('app/backups/mysql_temp.cnf');
                $configContent = "[client]\nuser={$username}\npassword={$password}\nhost={$host}\nport={$port}\n";
                file_put_contents($configFile, $configContent);
                
                $mysqldumpPath = "\"C:\\Program Files (x86)\\SICAR-8C460\\MySQL\\MySQL Server 5.5\\bin\\mysqldump.exe\"";
                $command = "{$mysqldumpPath} --defaults-file=\"{$configFile}\" --single-transaction --routines --triggers {$database} > \"{$filepath}\" 2>nul";
            } else {
                // Linux: comando directo (como antes)
                $command = "mysqldump -h{$host} -P{$port} -u{$username} -p{$password} --single-transaction --routines --triggers {$database} > \"{$filepath}\" 2>/dev/null";
            }
            
            Log::info("[$logId] COMANDO MYSQLDUMP", [
                'platform' => $isWindows ? 'windows' : 'linux',
                'command' => str_replace($password, '***', $command)
            ]);
            
            // Usar shell_exec para redirección que funciona mejor
            $execStart = microtime(true);
            
            // Para debugging en Windows, capturar errores
            if ($isWindows) {
                $debugCommand = str_replace(' 2>nul', ' 2>&1', $command);
                $result = shell_exec($debugCommand);
            } else {
                $result = shell_exec($command);
            }
            
            $execTime = round((microtime(true) - $execStart), 2);
            
            Log::info("[$logId] MYSQLDUMP EJECUTADO", [
                'execution_time' => "{$execTime}s",
                'result_length' => $result ? strlen($result) : 0,
                'result_preview' => $result ? substr($result, 0, 200) : null
            ]);
            
            // Verificar que el archivo se creó correctamente
            if (!file_exists($filepath)) {
                Log::error("[$logId] ARCHIVO NO EXISTE", ['filepath' => $filepath]);
                throw new Exception("Error: archivo backup no fue creado. Filepath: {$filepath}");
            }
            
            $filesize = filesize($filepath);
            if ($filesize == 0) {
                Log::error("[$logId] ARCHIVO VACIO", ['filepath' => $filepath]);
                throw new Exception("Error: archivo backup está vacío. Filepath: {$filepath}");
            }
            
            Log::info("[$logId] BACKUP SQL CREADO", [
                'filesize' => $filesize,
                'filesize_mb' => round($filesize / (1024 * 1024), 2)
            ]);
            
            // Limpiar archivo de configuración temporal en Windows
            if ($isWindows && isset($configFile) && file_exists($configFile)) {
                unlink($configFile);
                Log::info("[$logId] CONFIG FILE LIMPIADO", ['config_file' => $configFile]);
            }
            
            // 4. Comprimir archivo (Windows: PHP nativo, Linux: tar)
            if ($isWindows) {
                // Windows: usar gzip nativo de PHP
                $compressedFile = $filepath . '.gz';
                
                Log::info("[$logId] INICIANDO COMPRESIÓN PHP", ['method' => 'gzip_native']);
                $compressStart = microtime(true);
                
                $input = fopen($filepath, 'rb');
                $output = gzopen($compressedFile, 'wb9'); // máxima compresión
                
                if (!$input || !$output) {
                    throw new Exception("Error abriendo archivos para compresión");
                }
                
                while (!feof($input)) {
                    $chunk = fread($input, 8192); // chunks de 8KB
                    gzwrite($output, $chunk);
                }
                
                fclose($input);
                gzclose($output);
                
                $compressTime = round((microtime(true) - $compressStart), 2);
                
                Log::info("[$logId] COMPRESIÓN PHP COMPLETADA", [
                    'execution_time' => "{$compressTime}s",
                    'compressed_file' => $compressedFile
                ]);
                
            } else {
                // Linux: usar tar como antes
                $compressedFile = $filepath . '.tar.gz';
                
                $compressCommand = sprintf(
                    'tar -czf "%s" -C "%s" "%s" 2>/dev/null',
                    $compressedFile,
                    $backupDir,
                    $filename
                );
                
                Log::info("[$logId] COMANDO TAR", ['command' => $compressCommand]);
                
                $compressStart = microtime(true);
                exec($compressCommand, $output2, $return_code2);
                $compressTime = round((microtime(true) - $compressStart), 2);
                
                Log::info("[$logId] TAR EJECUTADO", [
                    'return_code' => $return_code2,
                    'execution_time' => "{$compressTime}s",
                    'output_lines' => count($output2)
                ]);
                
                if ($return_code2 !== 0 || !file_exists($compressedFile)) {
                    throw new Exception("Error comprimiendo backup con tar. Return code: {$return_code2}");
                }
            }
            
            if (!file_exists($compressedFile)) {
                throw new Exception("Error: archivo comprimido no fue creado");
            }
            
            // 5. Preparar respuesta con URL de descarga
            $compressedFilesize = filesize($compressedFile);
            
            Log::info("[$logId] ARCHIVO COMPRIMIDO", [
                'original_size_mb' => round($filesize / (1024 * 1024), 2),
                'compressed_size_mb' => round($compressedFilesize / (1024 * 1024), 2),
                'compression_ratio' => round(($compressedFilesize / $filesize) * 100, 1) . '%'
            ]);
            
            // Generar nombre único para descarga
            $downloadFilename = $logId . '_' . basename($compressedFile);
            $downloadPath = $backupDir . DIRECTORY_SEPARATOR . $downloadFilename;
            
            // Renombrar archivo con nombre único
            rename($compressedFile, $downloadPath);
            
            // Generar URL de descarga
            $baseUrl = config('app.url', 'https://tunnelcuspi.site');
            $downloadUrl = $baseUrl . '/api/backup/download/' . $downloadFilename;
            
            // Calcular tiempo de expiración (6 horas)
            $expiresAt = date('Y-m-d H:i:s', time() + (6 * 3600));
            
            // Limpiar archivo SQL original
            unlink($filepath);
            
            $totalTime = round((microtime(true) - $startTime), 2);
            
            Log::info("[$logId] BACKUP COMPLETADO", [
                'total_time' => "{$totalTime}s",
                'download_url' => $downloadUrl,
                'expires_at' => $expiresAt,
                'success' => true
            ]);
            
            // Si es modo webhook, enviar notificación
            if ($isWebhookMode && !empty($webhookUrl)) {
                $webhookData = [
                    'ok' => true,
                    'job_id' => $logId,
                    'status' => 'ready',
                    'download_url' => $downloadUrl,
                    'filename' => $downloadFilename,
                    'filesize' => $compressedFilesize,
                    'filesize_mb' => round($compressedFilesize / (1024 * 1024), 2),
                    'expires_at' => $expiresAt,
                    'timestamp' => $timestamp,
                    'compression' => $isWindows ? 'gzip' : 'tar.gz',
                    'platform' => $isWindows ? 'windows' : 'linux',
                    'total_time_seconds' => $totalTime
                ];
                
                // Enviar webhook con reintentos
                $webhookSent = false;
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    try {
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $webhookUrl);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhookData));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'User-Agent: TunnelCUSPI-Webhook/1.0'
                        ]);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        
                        if ($httpCode >= 200 && $httpCode < 300) {
                            Log::info("[$logId] WEBHOOK ENVIADO", [
                                'webhook_url' => $webhookUrl,
                                'attempt' => $attempt,
                                'http_code' => $httpCode
                            ]);
                            $webhookSent = true;
                            break;
                        } else {
                            Log::warning("[$logId] WEBHOOK FALLÓ INTENTO $attempt", [
                                'webhook_url' => $webhookUrl,
                                'http_code' => $httpCode,
                                'response' => substr($response, 0, 200)
                            ]);
                        }
                    } catch (Exception $webhookError) {
                        Log::warning("[$logId] WEBHOOK ERROR INTENTO $attempt", [
                            'webhook_url' => $webhookUrl,
                            'error' => $webhookError->getMessage()
                        ]);
                    }
                    
                    if ($attempt < 3) sleep(2); // Esperar antes del siguiente intento
                }
                
                if (!$webhookSent) {
                    Log::error("[$logId] WEBHOOK FALLÓ TODOS LOS INTENTOS", [
                        'webhook_url' => $webhookUrl
                    ]);
                }
                
                // En modo webhook, retornar respuesta simple
                return response()->json([
                    'ok' => true,
                    'job_id' => $logId,
                    'status' => 'completed',
                    'webhook_sent' => $webhookSent,
                    'total_time_seconds' => $totalTime
                ]);
            }
            
            return response()->json([
                'ok' => true,
                'type' => 'download_url',
                'download_url' => $downloadUrl,
                'filename' => $downloadFilename,
                'filesize' => $compressedFilesize,
                'filesize_mb' => round($compressedFilesize / (1024 * 1024), 2),
                'expires_at' => $expiresAt,
                'timestamp' => $timestamp,
                'compression' => $isWindows ? 'gzip' : 'tar.gz',
                'platform' => $isWindows ? 'windows' : 'linux',
                'log_id' => $logId
            ]);
            
        } catch (Exception $e) {
            $totalTime = round((microtime(true) - $startTime), 2);
            
            Log::error("[$logId] BACKUP FALLÓ", [
                'error' => $e->getMessage(),
                'total_time' => "{$totalTime}s",
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Limpiar archivos en caso de error
            if (isset($filepath) && file_exists($filepath)) {
                unlink($filepath);
                Log::info("[$logId] ARCHIVO SQL LIMPIADO", ['filepath' => $filepath]);
            }
            if (isset($compressedFile) && file_exists($compressedFile)) {
                unlink($compressedFile);
                Log::info("[$logId] ARCHIVO COMPRIMIDO LIMPIADO", ['filepath' => $compressedFile]);
            }
            if (isset($downloadPath) && file_exists($downloadPath)) {
                unlink($downloadPath);
                Log::info("[$logId] ARCHIVO DESCARGA LIMPIADO", ['filepath' => $downloadPath]);
            }
            // Limpiar archivo de configuración temporal en Windows
            if (isset($configFile) && file_exists($configFile)) {
                unlink($configFile);
                Log::info("[$logId] CONFIG FILE LIMPIADO (ERROR)", ['config_file' => $configFile]);
            }
            
            // Si es modo webhook, enviar notificación de error
            if ($isWebhookMode && !empty($webhookUrl)) {
                $webhookErrorData = [
                    'ok' => false,
                    'job_id' => $logId,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'platform' => strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'windows' : 'linux',
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                try {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhookErrorData));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    Log::info("[$logId] WEBHOOK ERROR ENVIADO", [
                        'webhook_url' => $webhookUrl,
                        'http_code' => $httpCode
                    ]);
                } catch (Exception $webhookError) {
                    Log::error("[$logId] WEBHOOK ERROR FALLÓ", [
                        'webhook_url' => $webhookUrl,
                        'webhook_error' => $webhookError->getMessage()
                    ]);
                }
                
                return response()->json([
                    'ok' => false,
                    'job_id' => $logId,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ], 500);
            }
            
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'platform' => strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'windows' : 'linux',
                'log_id' => $logId
            ], 500);
        }
    });
    
});

Route::middleware('validate.apikey')->get('/sync/departamentos', function (Request $request) {
    $departamentos = DB::table('departamento')
        ->select(
            'dep_id',
            'nombre',
            'restringido',
            'porcentaje',
            'system',
            'status',
            'comision'
        )
        ->orderBy('dep_id', 'asc')
        ->get();

    return response()->json([
        'ok' => true,
        'departamentos' => $departamentos,
    ]);
});

Route::middleware('validate.apikey')->get('/sync/categorias', function (Request $request) {
    $categorias = DB::table('categoria')
        ->select(
            'cat_id',
            'nombre',
            'system',
            'status',
            'dep_id',
            'comision'
        )
        ->orderBy('cat_id', 'asc')
        ->get();

    return response()->json([
        'ok' => true,
        'categorias' => $categorias,
    ]);
});

Route::middleware('validate.apikey')->get('/sync/articulos', function (Request $request) {
    $limit = $request->query('limit', 100);
    $offset = $request->query('offset', 0);

    $articulos = DB::table('articulo')
        ->select(
            'art_id',
            'clave',
            'claveAlterna',
            'descripcion',
            'servicio',
            'localizacion',
            'caracteristicas',
            'margen1',
            'margen2',
            'margen3',
            'margen4',
            'precio1',
            'precio2',
            'precio3',
            'precio4',
            'mayoreo1',
            'mayoreo2',
            'mayoreo3',
            'mayoreo4',
            'invMin',
            'invMax',
            'existencia',
            'status',
            'factor',
            'precioCompra',
            'preCompraProm',
            'unidadCompra',
            'unidadVenta',
            'cuentaPredial',
            'cat_id'
        )
        ->orderBy('art_id', 'asc')
        ->limit($limit)
        ->offset($offset)
        ->get();

    foreach ($articulos as $art) {
        $existenciaBodega = DB::connection('bodega')
            ->table('articulo')
            ->where('clave', $art->clave)
            ->value('existencia') ?? 0;

        $art->existencia_bodega = floatval($existenciaBodega);
    }
    
    return response()->json([
        'ok' => true,
        'articulos' => $articulos,
    ]);
});



