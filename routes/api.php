<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\TunnelController;
use App\Http\Controllers\Api\CotizacionController;

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
    Route::post('/cotizacion/crear', [CotizacionController::class, 'crear']);
    
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
    
    Route::post('/backup/full', function (Request $request) {
        $logId = uniqid('backup_');
        $startTime = microtime(true);
        
        // Log inicial
        Log::info("[$logId] BACKUP INICIADO", [
            'platform' => strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'windows' : 'linux',
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ]);
        
        try {
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
            $command = "mysqldump -h{$host} -P{$port} -u{$username} -p{$password} --single-transaction --routines --triggers {$database} > \"{$filepath}\"";
            
            if ($isWindows) {
                $command .= ' 2>nul';
            } else {
                $command .= ' 2>/dev/null';
            }
            
            Log::info("[$logId] COMANDO MYSQLDUMP", [
                'platform' => $isWindows ? 'windows' : 'linux',
                'command' => str_replace($password, '***', $command)
            ]);
            
            // Usar shell_exec para redirección que funciona mejor
            $execStart = microtime(true);
            $result = shell_exec($command);
            $execTime = round((microtime(true) - $execStart), 2);
            
            Log::info("[$logId] MYSQLDUMP EJECUTADO", [
                'execution_time' => "{$execTime}s",
                'result_length' => $result ? strlen($result) : 0
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
            
            // 4. Comprimir con tar.gz universal
            $compressedFile = $filepath . '.tar.gz';
            
            $compressCommand = sprintf(
                'tar -czf "%s" -C "%s" "%s"',
                $compressedFile,
                $backupDir,
                $filename
            );
            
            if ($isWindows) {
                $compressCommand .= ' 2>nul';
            } else {
                $compressCommand .= ' 2>/dev/null';
            }
            
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
                Log::error("[$logId] ERROR COMPRIMIENDO", [
                    'return_code' => $return_code2,
                    'file_exists' => file_exists($compressedFile),
                    'output' => $output2
                ]);
                throw new Exception("Error comprimiendo backup. Return code: {$return_code2}");
            }
            
            // 5. Preparar respuesta con chunks si es necesario
            $compressedFilesize = filesize($compressedFile);
            $maxChunkSize = 50 * 1024 * 1024; // 50MB para DO límite 60MB
            
            Log::info("[$logId] ARCHIVO COMPRIMIDO", [
                'original_size_mb' => round($filesize / (1024 * 1024), 2),
                'compressed_size_mb' => round($compressedFilesize / (1024 * 1024), 2),
                'compression_ratio' => round(($compressedFilesize / $filesize) * 100, 1) . '%'
            ]);
            
            // SIEMPRE usar chunks para evitar problemas de memoria
            $totalChunks = ceil($compressedFilesize / $maxChunkSize);
            $chunks = [];
            
            Log::info("[$logId] GENERANDO CHUNKS", [
                'total_chunks' => $totalChunks,
                'chunk_size_mb' => round($maxChunkSize / (1024 * 1024), 2)
            ]);
            
            $handle = fopen($compressedFile, 'rb');
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkData = fread($handle, $maxChunkSize);
                $chunks[] = [
                    'index' => $i,
                    'content' => base64_encode($chunkData),
                    'size' => strlen($chunkData)
                ];
                
                Log::info("[$logId] CHUNK GENERADO", [
                    'chunk_index' => $i,
                    'chunk_size_mb' => round(strlen($chunkData) / (1024 * 1024), 2)
                ]);
                
                // Liberar memoria del chunk procesado
                unset($chunkData);
            }
            fclose($handle);
            
            // Limpiar archivos temporales
            unlink($filepath);
            unlink($compressedFile);
            
            $totalTime = round((microtime(true) - $startTime), 2);
            
            Log::info("[$logId] BACKUP COMPLETADO", [
                'total_time' => "{$totalTime}s",
                'final_chunks' => count($chunks),
                'success' => true
            ]);
            
            return response()->json([
                'ok' => true,
                'type' => 'chunked',
                'filename' => basename($compressedFile),
                'total_chunks' => $totalChunks,
                'total_size' => $compressedFilesize,
                'chunks' => $chunks,
                'timestamp' => $timestamp,
                'compression' => 'tar.gz',
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

