# Sistema Backup TunnelCUSPI - Configuración

## Archivos del Sistema

### Windows (Laragon/XAMPP)
- `backup_worker.php` - Script principal backup Windows
- `backup_launcher.bat` - Launcher background Windows
- `backup_worker_test.php` - Script prueba 5seg

### Ubuntu/Linux  
- `backup_worker_ubuntu.php` - Script principal backup Ubuntu
- `backup_launcher.sh` - Launcher background Ubuntu  
- `backup_worker_test.php` - Script prueba (compatible)

## Configuración Inicial

### Windows
```bash
# Ya configurado en routes/api.php
$launcherPath = base_path('backup_launcher.bat');
```

### Ubuntu
1. Permisos ejecutable:
```bash
chmod +x backup_launcher.sh
```

2. Cambiar routes/api.php:
```php
// CAMBIAR PLATAFORMA AQUÍ
$isWindows = false; // true para Windows, false para Ubuntu

if ($isWindows) {
    $launcherPath = base_path('backup_launcher.bat');
    $backgroundCommand = sprintf('"%s" %s %s', $launcherPath, $logId, escapeshellarg($webhookUrl));
} else {
    $launcherPath = base_path('backup_launcher.sh');
    $backgroundCommand = sprintf('bash "%s" %s %s', $launcherPath, $logId, escapeshellarg($webhookUrl));
}
```

## Dependencias por Plataforma

### Windows
- PHP 7.4+ con extensiones: curl, openssl, gzip
- MySQL/MariaDB con mysqldump
- Laragon recomendado

### Ubuntu
- PHP 7.4+: `sudo apt install php7.4-cli php7.4-curl php7.4-mysql`
- MySQL: `sudo apt install mysql-client`
- Gzip: `sudo apt install gzip` (preinstalado)

## Testing

### Modo TEST (5 segundos)
```php
// routes/api.php
$useRealBackup = false;
```

### Modo REAL (3+ minutos)  
```php
// routes/api.php
$useRealBackup = true;
```

## Logs
- Backup específico: `storage/logs/backup_process.log`
- Laravel general: `storage/logs/laravel.log`

## Webhook Response
```json
{
  "job_id": "backup_66c123abc",
  "status": "completed",
  "filename": "backup_66c123abc_sicar_backup_2025-08-22_10-30-15.sql.gz", 
  "filesize_mb": "378.73",
  "download_url": "https://tunnelcuspi.site/api/backup/download/backup_66c123abc_sicar_backup_2025-08-22_10-30-15.sql.gz",
  "expires_at": "2025-08-22 16:30:15",
  "total_time_seconds": 187,
  "timestamp": "2025-08-22 10:30:15"
}
```