# 🔍 COMPARACIÓN CAMPO POR CAMPO - TABLA VENTA

**Fecha:** 2025-11-21
**Comparación:** Venta HOY (97577) vs Venta ANTERIOR válida (97570)

---

## 📊 RESUMEN EJECUTIVO

| Estado | Cantidad | Descripción |
|--------|----------|-------------|
| ✅ OK | 47 campos | Coinciden o son NULL en ambas |
| ❌ FALTANTES | 6 campos | NULL en HOY, tienen valor en ANTERIOR |
| ⚠️ INVESTIGAR | 3 campos | Diferentes pero pueden ser válidos |

---

## 📋 COMPARACIÓN DETALLADA (56 CAMPOS)

### GRUPO 1: Identificación y Fecha (2 campos)

| # | Campo | HOY (97577) | ANTERIOR (97570) | Estado | Notas |
|---|-------|-------------|------------------|--------|-------|
| 1 | ven_id | 97577 | 97570 | ✅ OK | Auto-increment |
| 2 | fecha | 2025-11-21 14:18:30 | 2025-11-19 11:41:41 | ✅ OK | Fecha de venta |

### GRUPO 2: Importes Principales (6 campos)

| # | Campo | HOY (97577) | ANTERIOR (97570) | Estado | Notas |
|---|-------|-------------|------------------|--------|-------|
| 3 | subtotal0 | 209.00 | 0.00 | ⚠️ INVESTIGAR | ¿Por qué 0.00 en anterior? |
| 4 | subtotal | 209.00 | 1627.20 | ✅ OK | Depende de artículos |
| 5 | descuento | 0.00 | 0.00 | ✅ OK | Sin descuento |
| 6 | total | 242.44 | 1887.55 | ✅ OK | Depende de artículos |
| 7 | cambio | 0.00 | 0.00 | ✅ OK | Sin cambio |
| 8 | letra | **"" (VACÍO)** | **(MIL OCHOCIENTOS... MN)** | ❌ **FALTANTE** | **Total en letras** |

### GRUPO 3: Moneda Extranjera (6 campos)

| # | Campo | HOY | ANTERIOR | Estado | Notas |
|---|-------|-----|----------|--------|-------|
| 9-14 | monSubtotal0...monLetra | NULL | NULL | ✅ OK | No se usa moneda extranjera |

### GRUPO 4: Configuración Moneda (3 campos)

| # | Campo | HOY | ANTERIOR | Estado | Notas |
|---|-------|-----|----------|--------|-------|
| 15 | monAbr | MXN | MXN | ✅ OK | Moneda MXN |
| 16 | monTipoCambio | 1.000000 | 1.000000 | ✅ OK | Sin tipo de cambio |
| 17 | decimales | 6 | 6 | ✅ OK | 6 decimales |

### GRUPO 5: Configuración Venta (3 campos)

| # | Campo | HOY | ANTERIOR | Estado | Notas |
|---|-------|-----|----------|--------|-------|
| 18 | comentario | "" | "" | ✅ OK | Sin comentario |
| 19 | porPeriodo | 0 | 0 | ✅ OK | No es por periodo |
| 20 | ventaPorAjuste | 0 | 0 | ✅ OK | No es ajuste |

### GRUPO 6: Programas Lealtad y Utilidades (9 campos) ⚠️

| # | Campo | HOY | ANTERIOR | Estado | Notas |
|---|-------|-----|----------|--------|-------|
| 21 | puntos | NULL | NULL | ✅ OK | No usa puntos |
| 22 | monedas | NULL | NULL | ✅ OK | No usa monedas |
| 23 | **peso** | **NULL** | **0.0000** | ❌ **FALTANTE** | **Peso total artículos** |
| 24 | **totalCompra** | **NULL** | **820.92** | ❌ **FALTANTE** | **Costo total** |
| 25 | **totalUtilidad** | **NULL** | **1066.63** | ❌ **FALTANTE** | **Utilidad total** |
| 26 | **subtotalCompra** | **NULL** | **707.70** | ❌ **FALTANTE** | **Costo subtotal** |
| 27 | **subtotalUtilidad** | **NULL** | **919.50** | ❌ **FALTANTE** | **Utilidad subtotal** |
| 28 | monedero | NULL | NULL | ✅ OK | No usa monedero |
| 29 | monMonedero | NULL | NULL | ✅ OK | No usa monedero |

### GRUPO 7: Auto-Facturación CFDI (11 campos)

| # | Campo | HOY | ANTERIOR | Estado | Notas |
|---|-------|-----|----------|--------|-------|
| 30-40 | afStatus...afEmail | NULL | NULL | ✅ OK | No es auto-factura |

### GRUPO 8: Totales Normalizados (5 campos)

| # | Campo | HOY | ANTERIOR | Estado | Notas |
|---|-------|-----|----------|--------|-------|
| 41-45 | origen...monDiferenciaTotal | NULL | NULL | ✅ OK | No se usan |

### GRUPO 9: Status (1 campo)

| # | Campo | HOY | ANTERIOR | Estado | Notas |
|---|-------|-----|----------|--------|-------|
| 46 | status | 1 | 1 | ✅ OK | Activa |

### GRUPO 10: Foreign Keys (10 campos)

| # | Campo | HOY | ANTERIOR | Estado | Notas |
|---|-------|-----|----------|--------|-------|
| 47 | tic_id | NULL | NULL | ✅ OK | No es ticket |
| 48 | not_id | NULL | NULL | ✅ OK | No es nota |
| 49 | rem_id | NULL | NULL | ✅ OK | No es remisión |
| 50 | caj_id | 1 | 1 | ✅ OK | Caja 1 |
| 51 | mon_id | 1 | 1 | ✅ OK | Moneda 1 (MXN) |
| 52 | **rcc_id** | **1** | **NULL** | ⚠️ **INVESTIGAR** | **¿Cliente ID?** |
| 53 | can_caj_id | NULL | NULL | ✅ OK | Sin cancelación |
| 54 | can_rcc_id | NULL | NULL | ✅ OK | Sin cancelación |
| 55 | vnd_id | NULL | 11 | ⚠️ OK | Venta sin vendedor vs con vendedor |
| 56 | rut_id | NULL | NULL | ✅ OK | Sin ruta |

---

## ❌ CAMPOS FALTANTES CRÍTICOS (6)

### 1. `letra` (Campo 8)
- **HOY:** `""` (VACÍO)
- **ANTERIOR:** `"(MIL OCHOCIENTOS OCHENTA Y SIETE PESOS 55/100 MN)"`
- **Impacto:** 🔴 CRÍTICO - SICAR necesita este campo para mostrar el total en letras
- **Solución:** Crear función `convertirTotalALetras(242.44)` → `"(DOSCIENTOS CUARENTA Y DOS PESOS 44/100 MN)"`

### 2. `peso` (Campo 23)
- **HOY:** `NULL`
- **ANTERIOR:** `0.0000`
- **Impacto:** 🟡 MEDIO - SICAR espera 0.0000 si no hay peso
- **Solución:** Usar `0.0000` por defecto

### 3. `totalCompra` (Campo 24)
- **HOY:** `NULL`
- **ANTERIOR:** `820.92`
- **Impacto:** 🟡 MEDIO - Reportes de utilidad no funcionarán
- **Cálculo:** `SUM(precioCompra × cantidad)` de todos los artículos
- **Ejemplo:** `110.00 × 1 = 110.00`

### 4. `totalUtilidad` (Campo 25)
- **HOY:** `NULL`
- **ANTERIOR:** `1066.63`
- **Impacto:** 🟡 MEDIO - Reportes de utilidad no funcionarán
- **Cálculo:** `total - totalCompra`
- **Ejemplo:** `242.44 - 110.00 = 132.44`

### 5. `subtotalCompra` (Campo 26)
- **HOY:** `NULL`
- **ANTERIOR:** `707.70`
- **Impacto:** 🟡 MEDIO - Reportes de utilidad no funcionarán
- **Cálculo:** `SUM(precioCompra × cantidad)` (igual que totalCompra)

### 6. `subtotalUtilidad` (Campo 27)
- **HOY:** `NULL`
- **ANTERIOR:** `919.50`
- **Impacto:** 🟡 MEDIO - Reportes de utilidad no funcionarán
- **Cálculo:** `subtotal - subtotalCompra`
- **Ejemplo:** `209.00 - 110.00 = 99.00`

---

## ⚠️ CAMPOS A INVESTIGAR (3)

### 1. `subtotal0` (Campo 3)
- **HOY:** `209.00`
- **ANTERIOR:** `0.00`
- **¿Por qué es 0.00 en la venta anterior?**
- **Hipótesis:** Podría ser subtotal de artículos exentos (sin impuestos)
- **Acción:** ✅ Verificar con más ventas antiguas

### 2. `rcc_id` (Campo 52)
- **HOY:** `1` (cli_id = 1)
- **ANTERIOR:** `NULL`
- **¿Qué es rcc_id?**
- **Según FK:** `fk_venta_resumenCorteCaja1` → tabla `resumencortecaja`
- **Problema:** En VentaController línea 169 usamos `rcc_id = cli_id` ❌ **INCORRECTO**
- **Acción:** ❌ **DEBE SER NULL** (no es cliente, es resumen de corte de caja)

### 3. `vnd_id` (Campo 55)
- **HOY:** `NULL` (sin vendedor)
- **ANTERIOR:** `11` (con vendedor)
- **Estado:** ✅ OK - Depende si la venta tiene vendedor asignado

---

## 🎯 CONCLUSIONES

### ✅ Campos OK (47/56 = 84%)
La mayoría de campos están correctos o son NULL válidos.

### ❌ Campos Faltantes (6/56 = 11%)
**CRÍTICO:**
- `letra` → Necesita conversión a letras

**IMPORTANTE:**
- `peso` → Usar 0.0000
- `totalCompra` → Calcular
- `totalUtilidad` → Calcular
- `subtotalCompra` → Calcular
- `subtotalUtilidad` → Calcular

### ⚠️ Campos Incorrectos (1/56 = 2%)
- `rcc_id` → Usar NULL, NO cli_id ❌

### 🔍 Campos a Investigar (2/56 = 4%)
- `subtotal0` → Verificar lógica
- `rcc_id` → Confirmar que debe ser NULL

---

## 📝 SIGUIENTE PASO

Verificar con más ventas antiguas para confirmar patrones de:
1. ¿`subtotal0` siempre es 0.00 en ventas reales de SICAR?
2. ¿`rcc_id` siempre es NULL en ventas normales?
3. ¿Hay casos donde `peso` tenga valor diferente a 0.0000?
