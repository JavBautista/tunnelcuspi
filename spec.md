# TunnelCUSPI - Sistema de Backup Completo

## 🎯 Contexto del Proyecto

**TunnelCUSPI** es un sistema backend Laravel 8 que funciona como API Gateway/Tunnel para sincronización de datos entre el servidor SICAR (Windows + Laragon) y CUSPI (Digital Ocean).

### 📋 Arquitectura Actual
- **Framework**: Laravel 8
- **Propósito**: Generar backups de BD SICAR y enviarlos vía API
- **Plataformas**: 
  - Desarrollo: Ubuntu (`/var/www/tunnelcuspi/`)
  - Producción: Windows 10 + Laragon (`C:\laragon\www\cuspi\`)

### 🔧 Configuración de Base de Datos

#### Windows Producción (.env):
```env
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=sicar
DB_USERNAME=root
DB_PASSWORD=RQYon9Ue
API_KEY_TUNNELCUSPI="uN4gFh7!rT3@kLp98#Qwz"
```

#### Ubuntu Desarrollo (.env):
```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sicar
DB_USERNAME=root
DB_PASSWORD=dbadmin
API_KEY_TUNNELCUSPI="uN4gFh7!rT3@kLp98#Qwz"
```

## 🚀 Sistema de Backup Implementado

### Endpoint Principal: `/api/backup/full`

**Método**: `POST`  
**Headers requeridos**: 
```
API-KEY: uN4gFh7!rT3@kLp98#Qwz
Content-Type: application/json
```

### 🔄 Flujo del Sistema

1. **Detección de Plataforma**: Automática (Windows vs Linux)
2. **Generación de Backup SQL**: 
   - Windows: `mysqldump --defaults-file=archivo.cnf`
   - Linux: `mysqldump -h -P -u -p comando directo`
3. **Compresión**: `tar.gz` universal
4. **Chunking**: Archivos > 50MB se dividen en chunks
5. **Respuesta**: JSON con chunks base64
6. **Limpieza**: Archivos temporales eliminados automáticamente

### 📊 Respuesta Exitosa
```json
{
  "ok": true,
  "type": "chunked",
  "filename": "sicar_backup_2025-08-22_00-44-08.sql.tar.gz",
  "total_chunks": 1,
  "total_size": 198,
  "chunks": [
    {
      "index": 0,
      "content": "H4sIAFm9p2gAA+3RwQrCMAwG4J...",
      "size": 198
    }
  ],
  "timestamp": "2025-08-22_00-44-08",
  "compression": "tar.gz",
  "platform": "windows",
  "log_id": "backup_68a7bd58b74a7"
}
```

### 🐛 Logging y Debugging

#### Endpoint de Logs: `/api/backup/logs`
**Método**: `GET`  
**Parámetros**:
- `lines=100` (número de líneas a mostrar)
- `filter=backup_xxxxx` (filtrar por log_id específico)

**Ejemplo de uso**:
```bash
curl -X GET "https://tunnelcuspi.site/api/backup/logs?lines=50&filter=backup_68a7bd58b74a7" \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz"
```

## 🧪 Testing y Validación

### 1. Probar Backup Completo
```bash
# En Windows Producción:
curl -X POST https://tunnelcuspi.site/api/backup/full \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz" \
  -H "Content-Type: application/json"

# En Ubuntu Desarrollo:
curl -X POST http://tunnelcuspi.test/api/backup/full \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz" \
  -H "Content-Type: application/json"
```

### 2. Verificar Estado de BD
```sql
-- Conectar a MySQL y verificar datos:
USE sicar;
SHOW TABLES;
SELECT COUNT(*) FROM articulo;
SELECT COUNT(*) FROM categoria;
SELECT COUNT(*) FROM departamento;
```

### 3. Verificar Logs
```bash
# Ver logs recientes con filtro:
curl -X GET "URL/api/backup/logs?lines=100&filter=backup" \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz"
```

## 📁 Estructura de Archivos Clave

```
/var/www/tunnelcuspi/
├── routes/api.php                 # Endpoints principales
├── storage/app/backups/           # Directorio temporal (auto-creado)
├── storage/logs/laravel.log       # Logs del sistema
├── .env                           # Configuración BD y API-KEY
└── config/apikey.php              # Configuración API-KEY
```

## 🔧 Comandos Útiles

### Desarrollo:
```bash
# Ver logs en tiempo real:
tail -f storage/logs/laravel.log | grep backup

# Limpiar cache:
php artisan config:clear && php artisan cache:clear

# Ver rutas:
php artisan route:list | grep backup
```

### Producción Windows:
```bash
# En Git Bash:
cd /c/laragon/www/cuspi

# Actualizar código:
git pull origin main

# Ver logs:
tail -n 50 storage/logs/laravel.log
```

## ⚠️ Troubleshooting

### Problema: Archivo backup vacío
**Síntoma**: `"error": "Error: archivo backup está vacío"`  
**Solución**: Verificar credenciales BD en .env y conectividad MySQL

### Problema: Error mysqldump
**Síntoma**: `execution_time` muy bajo (< 0.1s)  
**Debugging**: Ver `result_preview` en logs para error específico

### Problema: Error de permisos
**Síntoma**: No se puede crear directorio backup  
**Solución**: Verificar permisos en `storage/app/`

## 🎯 Estado Actual del Sistema

### ✅ Funcionalidades Completadas:
- [x] Detección automática de plataforma (Windows/Linux)
- [x] Comando mysqldump universal con archivo config en Windows
- [x] Compresión tar.gz multiplataforma
- [x] Sistema de chunks para archivos grandes
- [x] Logging detallado para debugging
- [x] Limpieza automática de archivos temporales
- [x] API-KEY authentication
- [x] Endpoint de logs para debugging

### 🧪 Testing Requerido:
- [ ] Verificar backup con BD real (datos de producción SICAR)
- [ ] Validar funcionamiento con BD > 1GB
- [ ] Confirmar chunks múltiples (archivos > 50MB)
- [ ] Testing de conectividad desde CUSPI (DO)

### 🚀 Próximos Pasos:
1. Conectar BD real de SICAR en Windows
2. Probar backup con datos reales (esperado: ~1.2GB → ~250MB comprimido)
3. Validar chunks múltiples (esperado: 5-6 chunks de 50MB cada uno)
4. Integrar con CUSPI para recepción de backups
5. Configurar cronjobs (2x/día: 6:00 AM / 6:00 PM)

## 📞 Información de Contacto y Estado

**Implementación**: ✅ COMPLETADA  
**Testing Windows**: ✅ FUNCIONANDO (BD vacía)  
**Testing Ubuntu**: ✅ FUNCIONANDO  
**Logging**: ✅ ACTIVO  
**Documentación**: ✅ COMPLETA  

**Última actualización**: 2025-08-22 00:44:09  
**Versión**: 1.0.0  
**Branch**: main  
**Commit**: f4fe027 (debug: capturar errores mysqldump)