# PROMPT COMPLETO PARA CLAUDE - PROYECTO CUSPI (DIGITAL OCEAN)

## 🎯 CONTEXTO DEL SISTEMA

Eres Claude trabajando en el proyecto **CUSPI** (Digital Ocean). Tu tarea es implementar un sistema de sincronización automática que descarga backups de base de datos desde **TunnelCUSPI** (servidor Windows SICAR) e importa los datos a tu base de datos PostgreSQL en Digital Ocean.

### 📋 ARQUITECTURA GENERAL
```
TunnelCUSPI (Windows SICAR) ←→ CUSPI (Digital Ocean)
         Laravel 8                    Django/FastAPI/etc
         MySQL BD                     PostgreSQL BD
         Puerto 3307                  Puerto 5432
```

## 🔗 SERVIDOR TUNNEL (YA IMPLEMENTADO)

### **URL Base**: `https://tunnelcuspi.site` (HTTP) o `http://tunnelcuspi.site` (fallback)
### **Autenticación**: `API-KEY: uN4gFh7!rT3@kLp98#Qwz`
### **Status**: ✅ PROBADO Y FUNCIONAL (Agosto 2025)

### **Endpoints Disponibles**:

#### 1. **POST /api/backup/full** - Solicitar Backup

### **MODO A: Webhook (Asincrónico) - RECOMENDADO ✅**
```bash
curl -X POST http://tunnelcuspi.site/api/backup/full \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz" \
  -H "Content-Type: application/json" \
  -d '{"webhook_url": "https://cuspi.do/webhook/backup-ready"}'
```

**Respuesta Inmediata** (~1 segundo):
```json
{
  "ok": true,
  "type": "webhook",
  "job_id": "backup_68a7d15a36be4",
  "status": "processing", 
  "message": "Backup iniciado en background",
  "webhook_url": "https://cuspi.do/webhook/backup-ready",
  "timestamp": "2025-08-22 02:12:36"
}
```

**Webhook Enviado** (~3 minutos después):
```bash
# TunnelCUSPI envía POST a https://cuspi.do/webhook/backup-ready
POST https://cuspi.do/webhook/backup-ready
Content-Type: application/json
User-Agent: TunnelCUSPI-Webhook/1.0

# CASO ÉXITO:
{
  "backup_id": "backup_68a7d15a36be4",
  "status": "completed",
  "download_url": "http://tunnelcuspi.site/api/backup/download/backup_68a7d15a36be4_sicar_backup_2025-08-22_02-48-09.sql.gz",
  "expires_at": "2025-08-22 08:51:33",
  "file_size_mb": 378.61,
  "compression_ratio": "32.8%"
}

# CASO ERROR:
{
  "backup_id": "backup_68a7d15a36be4",
  "status": "failed", 
  "error": "Error comprimiendo backup"
}
```

### **MODO B: Sincrónico (Compatibilidad) ✅**  
```bash
curl -X POST http://tunnelcuspi.site/api/backup/full \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz" \
  -H "Content-Type: application/json" \
  -d "{}"
```

**Respuesta Exitosa** (~3 minutos 25 segundos):
```json
{
  "ok": true,
  "type": "download_url", 
  "download_url": "http://tunnelcuspi.site/api/backup/download/backup_68a7da68aabff_sicar_backup_2025-08-22_02-48-09.sql.gz",
  "filename": "backup_68a7da68aabff_sicar_backup_2025-08-22_02-48-09.sql.gz",
  "filesize": 396998348,
  "filesize_mb": 378.61,
  "expires_at": "2025-08-22 08:51:33",
  "timestamp": "2025-08-22_02-48-09",
  "compression": "gzip",
  "platform": "windows",
  "log_id": "backup_68a7da68aabff"
}
```

**Respuesta de Error**:
```json
{
  "ok": false,
  "error": "Error description",
  "platform": "windows",
  "log_id": "backup_12345"
}
```

#### 2. **GET /api/backup/download/{filename}** - Descargar Archivo ✅
```bash
curl -X GET http://tunnelcuspi.site/api/backup/download/backup_68a7da68aabff_sicar_backup_2025-08-22_02-48-09.sql.gz \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz" \
  --output backup.sql.gz
```

**Respuesta**: Archivo binario comprimido gzip (~379MB)
**Tiempo descarga**: ~2-5 minutos (dependiendo conexión)
**Expiración**: 6 horas desde generación

#### 3. **🔄 Sistema de Limpieza Automática**
- **Automático**: Mantiene máximo 2 backups más recientes
- **Al generar**: Elimina automáticamente backups antiguos 
- **Logs**: Registra archivos eliminados con tamaño

**Ejemplo de logs**:
```json
{"message": "ARCHIVO ANTIGUO ELIMINADO", "filename": "backup_old.sql.gz", "size_mb": 378.61}
```

#### 4. **⚡ Retry Logic del Webhook**
- **Intentos**: 3 intentos automáticos
- **Backoff**: Exponencial (1s, 4s, 16s)
- **Timeout**: 30 segundos por intento
- **Logs**: Registra cada intento y resultado final

## ✅ RESULTADOS DE PRUEBAS REALES

### **Prueba Exitosa - Agosto 2025**
```json
{
  "test_date": "2025-08-22",
  "modo_sincronico": {
    "status": "✅ EXITOSO",
    "duracion": "3 minutos 25 segundos",
    "archivo_generado": "backup_68a7da68aabff_sicar_backup_2025-08-22_02-48-09.sql.gz",
    "tamaño_original": "1155.15 MB",
    "tamaño_comprimido": "378.61 MB", 
    "compresion": "32.8%",
    "download_url": "http://tunnelcuspi.site/api/backup/download/backup_68a7da68aabff_sicar_backup_2025-08-22_02-48-09.sql.gz"
  },
  "limpieza_automatica": {
    "status": "✅ EXITOSO",
    "archivos_eliminados": 2,
    "storage_management": "Solo 2 backups mantenidos"
  },
  "performance": {
    "mysqldump_time": "103.2s",
    "compression_time": "101.41s", 
    "total_time": "205.18s"
  }
}
```

## 🎯 TU MISIÓN EN CUSPI (DO)

### **TAREA PRINCIPAL**: Implementar sistema de sincronización automática

### **COMPONENTES A DESARROLLAR**:

#### 1. **Módulo de Backup Downloader**
```python
class SicarBackupDownloader:
    def __init__(self):
        self.api_key = "uN4gFh7!rT3@kLp98#Qwz"
        self.base_url = "https://tunnelcuspi.site"
        
    def request_backup_webhook(self, webhook_url):
        """Solicita backup con webhook (RECOMENDADO)"""
        # POST /api/backup/full con {"webhook_url": "..."}
        # Retorna inmediato: {"job_id": "...", "status": "processing"}
        
    def request_backup_sync(self):
        """Solicita backup sincrónico (compatibilidad)"""
        # POST /api/backup/full sin webhook_url
        # Retorna después de 3min: {"download_url": "..."}
        
    def download_backup(self, download_url):
        """Descarga archivo comprimido desde URL"""
        # GET /api/backup/download/{filename}
        # Retorna: archivo .sql.gz
        
    def verify_backup(self, filepath):
        """Verifica integridad del archivo descargado"""
        # Verificar tamaño, formato, puede descomprimir
```

#### **Webhook Handler (NUEVO)**
```python
class WebhookHandler:
    def handle_backup_ready(self, webhook_data):
        """Maneja webhook cuando backup está listo"""
        # webhook_data = {
        #   "backup_id": "backup_68a7d15a36be4",
        #   "status": "completed", 
        #   "download_url": "http://tunnelcuspi.site/api/backup/download/file.sql.gz",
        #   "expires_at": "2025-08-22 08:51:33",
        #   "file_size_mb": 378.61,
        #   "compression_ratio": "32.8%"
        # }
        
    def handle_backup_failed(self, webhook_data):
        """Maneja webhook cuando backup falló"""
        # webhook_data = {
        #   "backup_id": "backup_68a7d15a36be4",
        #   "status": "failed",
        #   "error": "Error comprimiendo backup"
        # }
        
    @app.route('/webhook/backup-ready', methods=['POST'])
    def webhook_endpoint(self):
        """Endpoint para recibir webhooks de TunnelCUSPI"""
        # DEBE responder {"ok": True} para confirmar recepción
        # TunnelCUSPI reintentará 3 veces si no recibe 200 OK
        data = request.get_json()
        
        if data['status'] == 'completed':
            # Iniciar descarga e importación automática
            process_backup_async.delay(data)
        elif data['status'] == 'failed':
            # Manejar error y notificar
            handle_backup_error(data['error'])
            
        return {"ok": True}
```

#### 2. **Módulo de Procesamiento de Datos**
```python
class BackupProcessor:
    def decompress_backup(self, gz_filepath):
        """Descomprime archivo .gz a .sql"""
        
    def parse_mysql_dump(self, sql_filepath):
        """Convierte MySQL dump a comandos PostgreSQL"""
        # IMPORTANTE: Convertir sintaxis MySQL → PostgreSQL
        # - AUTO_INCREMENT → SERIAL
        # - MyISAM → InnoDB adaptations
        # - DATE/DATETIME formats
        # - Escape characters
        
    def import_to_postgres(self, processed_sql):
        """Importa datos a PostgreSQL"""
        # Conectar a BD PostgreSQL local
        # Ejecutar comandos SQL procesados
        # Manejar errores de importación
```

#### 3. **Scheduler/Cronjob**
```python
class BackupScheduler:
    def run_sync(self):
        """Ejecuta sincronización completa"""
        # 1. Solicitar backup
        # 2. Descargar archivo
        # 3. Procesar e importar
        # 4. Logging y notificaciones
        # 5. Limpieza de archivos temporales
        
    def setup_cron(self):
        """Configura ejecución automática"""
        # Cada 12 horas: 6:00 AM / 6:00 PM
```

#### 4. **Sistema de Logging y Monitoreo**
```python
class SyncLogger:
    def log_sync_start(self):
        """Log inicio de sincronización"""
        
    def log_download_progress(self, progress):
        """Log progreso de descarga"""
        
    def log_import_results(self, stats):
        """Log resultados de importación"""
        
    def send_notifications(self, status, details):
        """Enviar notificaciones de éxito/error"""
```

## 📊 ESTRUCTURA DE DATOS SICAR

### **Tablas Principales**:
```sql
-- Tabla: articulo (productos)
CREATE TABLE articulo (
    art_id INT PRIMARY KEY AUTO_INCREMENT,
    clave VARCHAR(50),
    claveAlterna VARCHAR(50),
    descripcion TEXT,
    servicio TINYINT,
    localizacion VARCHAR(100),
    caracteristicas TEXT,
    margen1 DECIMAL(10,2),
    margen2 DECIMAL(10,2),
    margen3 DECIMAL(10,2),
    margen4 DECIMAL(10,2),
    precio1 DECIMAL(10,2),
    precio2 DECIMAL(10,2),
    precio3 DECIMAL(10,2),
    precio4 DECIMAL(10,2),
    mayoreo1 DECIMAL(10,2),
    mayoreo2 DECIMAL(10,2),
    mayoreo3 DECIMAL(10,2),
    mayoreo4 DECIMAL(10,2),
    invMin DECIMAL(10,2),
    invMax DECIMAL(10,2),
    existencia DECIMAL(10,2),
    status TINYINT,
    factor DECIMAL(10,4),
    precioCompra DECIMAL(10,2),
    preCompraProm DECIMAL(10,2),
    unidadCompra VARCHAR(20),
    unidadVenta VARCHAR(20),
    cuentaPredial VARCHAR(50),
    cat_id INT
);

-- Tabla: categoria
CREATE TABLE categoria (
    cat_id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100),
    system TINYINT,
    status TINYINT,
    dep_id INT,
    comision DECIMAL(5,2)
);

-- Tabla: departamento
CREATE TABLE departamento (
    dep_id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100),
    restringido TINYINT,
    porcentaje DECIMAL(5,2),
    system TINYINT,
    status TINYINT,
    comision DECIMAL(5,2)
);
```

## 🔧 CONFIGURACIÓN REQUERIDA

### **Variables de Entorno**:
```env
# TunnelCUSPI API
TUNNEL_API_URL=https://tunnelcuspi.site
TUNNEL_API_KEY=uN4gFh7!rT3@kLp98#Qwz

# PostgreSQL Local
DATABASE_URL=postgresql://user:password@localhost:5432/cuspi_db

# Configuración Sync
SYNC_INTERVAL_HOURS=12
BACKUP_RETENTION_DAYS=7
NOTIFICATION_EMAIL=admin@cuspi.com
LOG_LEVEL=INFO
```

### **Dependencias Python**:
```txt
requests>=2.31.0
psycopg2-binary>=2.9.0
schedule>=1.2.0
python-decouple>=3.8
sqlparse>=0.4.0
gzip
logging
```

## ⚠️ CONSIDERACIONES CRÍTICAS

### **1. Manejo de Errores**:
- **Timeout de descarga**: Archivo 400MB puede tardar varios minutos
- **Espacio en disco**: Verificar espacio disponible antes de descargar
- **Conexión de red**: Implementar reintentos con backoff exponencial
- **Errores de importación**: Rollback en caso de falla parcial

### **2. Conversión MySQL → PostgreSQL**:
```sql
-- Ejemplos de conversiones necesarias:
-- MySQL: AUTO_INCREMENT
-- PostgreSQL: SERIAL o IDENTITY

-- MySQL: `column`
-- PostgreSQL: "column"

-- MySQL: DATE '2025-01-01'
-- PostgreSQL: DATE '2025-01-01' (igual)

-- MySQL: TINYINT
-- PostgreSQL: SMALLINT
```

### **3. Rendimiento**:
- **Batch inserts**: Insertar en lotes de 1000 registros
- **Índices**: Crear índices después de importación
- **Transacciones**: Usar transacciones para integridad
- **Vacuum**: Ejecutar VACUUM ANALYZE después de importación

### **4. Seguridad**:
- **API Key**: Nunca hardcodear en código
- **Conexiones**: Usar SSL para conexiones BD
- **Archivos temporales**: Eliminar después de uso
- **Logs**: No logear datos sensibles

## 🚀 FLUJO DE EJECUCIÓN COMPLETO

### **Secuencia de Pasos WEBHOOK (RECOMENDADO)**:
```python
# PASO 1: Solicitar backup con webhook
def request_sicar_backup():
    logger.info("🚀 SOLICITANDO BACKUP SICAR")
    
    webhook_url = "https://cuspi.do/webhook/backup-ready"
    response = downloader.request_backup_webhook(webhook_url)
    
    logger.info(f"📋 Job ID: {response['job_id']}")
    return response['job_id']

# PASO 2: Endpoint webhook (automático)
@app.route('/webhook/backup-ready', methods=['POST'])
def webhook_backup_ready():
    webhook_data = request.get_json()
    
    if webhook_data['status'] == 'ready':
        # Procesar backup automáticamente
        process_backup_async.delay(webhook_data)
        return {"ok": True, "message": "Backup processing started"}
    
    elif webhook_data['status'] == 'failed':
        logger.error(f"❌ BACKUP FALLÓ: {webhook_data['error']}")
        send_error_notification(webhook_data['error'])
        return {"ok": True, "message": "Error notification sent"}

# PASO 3: Procesamiento asincrónico
def process_backup_async(webhook_data):
    logger.info("🚀 PROCESANDO BACKUP RECIBIDO")
    job_id = webhook_data['job_id']
    
    try:
        # 1. Descargar archivo
        logger.info(f"⬇️ Descargando {webhook_data['filesize_mb']}MB...")
        filepath = downloader.download_backup(webhook_data['download_url'])
        
        # 2. Verificar descarga
        logger.info("🔍 Verificando archivo descargado...")
        processor.verify_backup(filepath)
        
        # 3. Descomprimir
        logger.info("📂 Descomprimiendo backup...")
        sql_file = processor.decompress_backup(filepath)
        
        # 4. Procesar SQL
        logger.info("🔄 Convirtiendo MySQL → PostgreSQL...")
        processed_sql = processor.parse_mysql_dump(sql_file)
        
        # 5. Importar a PostgreSQL
        logger.info("📥 Importando datos a PostgreSQL...")
        import_stats = processor.import_to_postgres(processed_sql)
        
        # 6. Limpieza
        logger.info("🧹 Limpiando archivos temporales...")
        cleanup_temp_files([filepath, sql_file])
        
        # 7. Notificación de éxito
        logger.info("✅ SINCRONIZACIÓN COMPLETADA")
        send_success_notification(import_stats, job_id)
        
    except Exception as e:
        logger.error(f"❌ ERROR EN SINCRONIZACIÓN: {e}")
        send_error_notification(str(e), job_id)
        raise
```

### **Secuencia SINCRÓNICA (Alternativa)**:
```python
def sync_sicar_data_sync():
    logger.info("🚀 INICIANDO SINCRONIZACIÓN SICAR (SINCRÓNICA)")
    
    try:
        # 1. Solicitar backup (espera 3 minutos)
        logger.info("📤 Solicitando backup a TunnelCUSPI...")
        backup_info = downloader.request_backup_sync()
        
        if not backup_info['ok']:
            raise Exception(f"Error en backup: {backup_info['error']}")
            
        # 2. Continuar con descarga e importación...
        # (resto del flujo igual)
        
    except Exception as e:
        logger.error(f"❌ ERROR EN SINCRONIZACIÓN: {e}")
        send_error_notification(str(e))
        raise
```

## 📋 CRITERIOS DE ACEPTACIÓN

### **Funcionalidades Obligatorias**:
- [ ] Solicitar backup a TunnelCUSPI via API
- [ ] Descargar archivo comprimido (400MB)
- [ ] Descomprimir archivo .gz
- [ ] Convertir sintaxis MySQL → PostgreSQL  
- [ ] Importar datos a PostgreSQL local
- [ ] Logging completo del proceso
- [ ] Manejo de errores y reintentos
- [ ] Limpieza de archivos temporales
- [ ] Notificaciones de éxito/error
- [ ] Programación automática (cronjob)

### **Métricas de Éxito**:
- **Tiempo total**: < 15 minutos end-to-end
- **Éxito de descarga**: 99.9% confiabilidad
- **Integridad de datos**: 100% registros importados
- **Disponibilidad**: Sync cada 12 horas sin fallos

## 🔧 COMANDOS DE TESTING

### **Probar Manualmente (COMANDOS VALIDADOS)**:
```bash
# 1. Probar solicitud de backup SINCRÓNICO ✅
curl -X POST http://tunnelcuspi.site/api/backup/full \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz" \
  -H "Content-Type: application/json" \
  -d "{}"

# 2. Probar solicitud de backup WEBHOOK ✅  
curl -X POST http://tunnelcuspi.site/api/backup/full \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz" \
  -H "Content-Type: application/json" \
  -d '{"webhook_url": "https://tu-dominio.com/webhook/backup-ready"}'

# 3. Probar descarga (usar URL real del paso 1) ✅
curl -X GET "http://tunnelcuspi.site/api/backup/download/backup_68a7da68aabff_sicar_backup_2025-08-22_02-48-09.sql.gz" \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz" \
  --output test_backup.sql.gz

# 4. Verificar archivo descargado ✅
file test_backup.sql.gz
gunzip -t test_backup.sql.gz
ls -lh test_backup.sql.gz  # Debe mostrar ~379MB
```

### **Validar Datos Importados**:
```sql
-- Verificar conteos
SELECT COUNT(*) FROM articulo;
SELECT COUNT(*) FROM categoria;  
SELECT COUNT(*) FROM departamento;

-- Verificar integridad
SELECT art_id, clave, descripcion FROM articulo LIMIT 5;
```

## 📞 INFORMACIÓN ADICIONAL

### **Contacto TunnelCUSPI**:
- **URL**: http://tunnelcuspi.site (usar HTTP, no HTTPS)
- **Status**: ✅ OPERATIVO Y PROBADO (Agosto 2025)
- **Backup Size**: ~379MB comprimido (~1155MB descomprimido)
- **Tiempo generación**: ~3 minutos 25 segundos 
- **Expiración archivos**: 6 horas
- **Performance real**:
  - MySQL dump: ~103 segundos
  - Compresión gzip: ~101 segundos  
  - Total end-to-end: ~205 segundos
- **Storage**: Máximo 2 backups (limpieza automática)

### **Datos de Testing**:
- **Tablas principales**: articulo (~50K registros), categoria (~200), departamento (~20)
- **Campos críticos**: art_id, clave, descripcion, precios, existencia
- **Relaciones**: articulo.cat_id → categoria.cat_id, categoria.dep_id → departamento.dep_id

---

## 🎯 OBJETIVO FINAL

**Implementar sistema completamente automatizado** que sincronice datos SICAR → CUSPI cada 12 horas, con monitoreo, logging y notificaciones, manteniendo integridad de datos y alta disponibilidad.

**¡MANOS A LA OBRA!** 🚀