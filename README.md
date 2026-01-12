# FerraWin Sync

Sistema de sincronización entre FerraWin (SQL Server) y la aplicación Manager (Laravel).

## Arquitectura

```
┌─────────────────────────────────────────────────────────────────┐
│                     RED LOCAL (Oficina)                         │
│                                                                 │
│   ┌─────────────────┐       ┌─────────────────────┐            │
│   │    FerraWin     │       │   ferrawin-sync     │            │
│   │   SQL Server    │◄──────│   (Script PHP)      │            │
│   │  192.168.0.7    │       │                     │            │
│   └─────────────────┘       └──────────┬──────────┘            │
│                                        │                        │
└────────────────────────────────────────┼────────────────────────┘
                                         │
                                         │ HTTPS (API POST)
                                         ▼
                            ┌─────────────────────────┐
                            │  Servidor Producción    │
                            │ app.hierrospacoreyes.es │
                            │       (Laravel)         │
                            └─────────────────────────┘
```

## Estructura del Proyecto

```
ferrawin-sync/
├── sync-optimizado.php    ← Script principal (RECOMENDADO)
├── sync.php               ← Script antiguo (más opciones, menos eficiente)
├── test-connection.php    ← Verificar conexiones
├── check_datos.php        ← Debug de planilla específica
├── src/
│   ├── Config.php         ← Configuración desde .env
│   ├── Database.php       ← Conexión SQL Server
│   ├── FerrawinQuery.php  ← Consultas a FerraWin
│   ├── ApiClient.php      ← Cliente HTTP para enviar a Laravel
│   └── Logger.php         ← Sistema de logs
├── logs/                  ← Logs diarios (sync-YYYY-MM-DD.log)
├── .env                   ← Configuración (credenciales, URLs)
└── vendor/                ← Dependencias (Composer)
```

## Scripts Disponibles

### 1. `sync-optimizado.php` (RECOMENDADO)

Script principal optimizado para sincronización.

**Características:**
- Envía planillas en **batches de 10** (más eficiente)
- Soporte para **múltiples destinos** (local/production)
- Filtra por **año contable** (ZCONTA)
- Sistema de **pausa/reanudación**
- Logs detallados

**Uso:**
```bash
# Sincronizar año 2025 a producción
php sync-optimizado.php --año 2025 --target production

# Sincronizar año 2025 a local
php sync-optimizado.php --año 2025 --target local

# Continuar desde una planilla específica
php sync-optimizado.php --año 2025 --desde-codigo 2025-007500 --target production

# Probar con 10 planillas
php sync-optimizado.php --test 10 --target local

# Sincronizar todo (sin filtro de año)
php sync-optimizado.php --todos --target production

# Ver qué se sincronizaría (sin enviar)
php sync-optimizado.php --año 2025 --dry-run
```

### 2. `sync.php` (Antiguo)

Script original con más opciones pero menos eficiente.

**Uso:**
```bash
# Sincronizar últimos 7 días
php sync.php

# Sincronizar últimos 30 días
php sync.php 30

# Sincronizar año específico
php sync.php --año 2024

# Sincronizar rango de fechas
php sync.php --desde 2024-01-01 --hasta 2024-06-30

# Ver estadísticas de FerraWin
php sync.php --stats

# Modo prueba (5 planillas)
php sync.php --test 5

# Simulación sin enviar
php sync.php --dry-run

# Ayuda
php sync.php --help
```

### 3. `test-connection.php`

Verifica conexión a FerraWin y a la API.

```bash
php test-connection.php
```

### 4. `check_datos.php`

Muestra los datos que se extraerían de una planilla específica.

```bash
php check_datos.php 2025-009123
```

---

## Comparativa: sync-optimizado.php vs sync.php

| Característica | sync-optimizado.php | sync.php |
|----------------|---------------------|----------|
| **Envío de datos** | Batches de 10 planillas | Todo junto al final |
| **Eficiencia memoria** | Alta (procesa y envía) | Baja (acumula todo) |
| **Target local/prod** | ✅ Sí (`--target`) | ❌ No (solo .env) |
| **Filtro por año** | ZCONTA (código planilla) | YEAR(ZFECHA) (fecha) |
| **Modo dry-run** | ✅ Sí | ✅ Sí |
| **Estadísticas** | ❌ No | ✅ Sí (`--stats`) |
| **Rango de fechas** | ❌ No | ✅ Sí (`--desde/--hasta`) |
| **Días atrás** | ❌ No | ✅ Sí (número directo) |
| **Pausa/Reanudación** | ✅ Sí | ✅ Sí |
| **Recomendado** | ✅ **SÍ** | ❌ Obsoleto |

### ¿Cuál usar?

**Usa `sync-optimizado.php`** para:
- Sincronización diaria/automática
- Migración de años completos
- Elegir destino (local o producción)

**Usa `sync.php`** solo para:
- Ver estadísticas de FerraWin (`--stats`)
- Sincronizar por rango de fechas específico

---

## Configuración (.env)

```env
# Base de datos FerraWin (SQL Server)
FERRAWIN_HOST=192.168.0.7
FERRAWIN_PORT=1433
FERRAWIN_DATABASE=FERRAWIN
FERRAWIN_USERNAME=sa
FERRAWIN_PASSWORD=tu-password

# Servidor LOCAL (desarrollo)
LOCAL_URL=http://127.0.0.1/manager/public/
LOCAL_TOKEN=tu-token-local

# Servidor PRODUCCIÓN
PRODUCTION_URL=https://app.hierrospacoreyes.es/
PRODUCTION_TOKEN=tu-token-produccion

# Opciones
SYNC_COMPRESS=true
LOG_LEVEL=info
```

---

## Archivos de Control

| Archivo | Propósito |
|---------|-----------|
| `sync.pid` | Contiene el PID del proceso en ejecución |
| `sync.pause` | Si existe, el proceso se pausa al terminar el batch actual |

Para pausar una sincronización en ejecución:
```bash
touch sync.pause
```

---

## Logs

Los logs se guardan en `logs/sync-YYYY-MM-DD.log`:

```
[2026-01-12 14:00:01] INFO: === Sincronización Optimizada FerraWin ===
[2026-01-12 14:00:01] INFO: Target: production (https://app.hierrospacoreyes.es/)
[2026-01-12 14:00:02] INFO: Conexión a FerraWin OK
[2026-01-12 14:00:02] INFO: Encontradas 156 planillas CON DATOS
[2026-01-12 14:00:05] INFO: [1/156] Preparando 2025-009123 (24 elementos)
[2026-01-12 14:00:08] INFO: [2/156] Preparando 2025-009122 (8 elementos)
...
[2026-01-12 14:00:15] INFO: Enviando batch de 10 planillas...
[2026-01-12 14:00:18] INFO: Batch OK: 10 planillas
...
[2026-01-12 14:05:30] INFO: === Sincronización completada ===
```

---

## Flujo de Ejecución

```
1. Cargar configuración (.env)
2. Conectar a FerraWin (SQL Server)
3. Verificar conexión a API destino
4. Obtener lista de planillas (filtrada por año)
5. Por cada planilla:
   a. Extraer datos de cabecera (ORD_HEAD)
   b. Extraer elementos (ORD_BAR)
   c. Extraer entidades/ensamblajes (ORD_DET)
   d. Formatear para API
   e. Añadir al batch
6. Cada 10 planillas → enviar batch a API
7. API (Laravel) procesa e importa:
   - Crear/actualizar Cliente y Obra
   - Crear Planilla
   - Crear Etiquetas y Elementos
   - Asignar máquinas
   - Crear órdenes de planilla
8. Registrar resultado en log
```

---

## Automatización (Tarea Programada)

Para ejecutar automáticamente cada día:

### Windows (Programador de Tareas)

1. Crear archivo `sync-diario.bat`:
```batch
@echo off
cd /d C:\ferrawin-sync
C:\php\php.exe sync-optimizado.php --año 2025 --target production
```

2. Crear tarea programada:
   - Programa: `C:\ferrawin-sync\sync-diario.bat`
   - Desencadenador: Diario a las 14:00
   - Ejecutar con privilegios más altos: Sí

### Linux (Cron)

```bash
# Editar crontab
crontab -e

# Añadir línea (ejecutar a las 14:00 cada día)
0 14 * * * cd /var/www/ferrawin-sync && php sync-optimizado.php --año 2025 --target production >> /var/log/ferrawin-sync.log 2>&1
```

---

## Requisitos

- PHP 7.4+ con extensiones:
  - `pdo_sqlsrv` (SQL Server)
  - `curl`
  - `json`
- Acceso de red a FerraWin (192.168.0.7:1433)
- Acceso a internet (para enviar a producción)
- Token de API válido

---

## Solución de Problemas

### No conecta a FerraWin
```bash
php test-connection.php
```
- Verificar IP y puerto en .env
- Verificar credenciales
- Verificar firewall permite conexión

### No conecta a producción
- Verificar URL en .env
- Verificar token válido
- Verificar certificado SSL

### Timeout en batch grande
- Usar `--test 5` para probar con pocas planillas
- Verificar conexión de red estable

### Planillas no aparecen
- Verificar que tienen elementos en ORD_BAR
- Verificar año correcto (ZCONTA)
- Usar `check_datos.php` para debug

---

## Última Actualización

**Fecha:** 2026-01-12

**Cambios recientes:**
- Filtro por ZCONTA en lugar de YEAR(ZFECHA)
- Soporte multi-target (local/production)
- Sistema de pausa/reanudación
- Envío en batches de 10 planillas
