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
│                                        │ ▲                      │
└────────────────────────────────────────┼─┼──────────────────────┘
                                         │ │
                         HTTPS (API POST)│ │ WebSocket (Pusher)
                                         ▼ │
                            ┌─────────────────────────┐
                            │  Servidor Producción    │
                            │ app.hierrospacoreyes.es │
                            │       (Laravel)         │
                            └─────────────────────────┘
```

El sistema tiene **dos canales de comunicación**:
- **Salida (→):** `sync-optimizado.php` envía planillas por HTTPS a la API de producción
- **Entrada (←):** `sync-listener.php` mantiene una conexión WebSocket con Pusher y recibe comandos desde la UI de producción

## Estructura del Proyecto

```
ferrawin-sync/
├── sync-optimizado.php            ← Script principal de sincronización (RECOMENDADO)
├── sync.php                       ← Script antiguo (más opciones, menos eficiente)
├── sync-listener.php              ← Daemon WebSocket — escucha comandos remotos vía Pusher
├── start-listener.bat             ← Inicia el listener (con ventana, para debug)
├── start-listener-background.vbs ← Inicia el listener en segundo plano (sin ventana)
├── stop-listener.bat              ← Detiene el listener
├── install-scheduled-task.ps1    ← Instala arranque automático en Task Scheduler
├── test-connection.php            ← Verificar conexiones
├── check_datos.php                ← Debug de planilla específica
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

## El Listener — Control Remoto desde Producción

`sync-listener.php` es un proceso PHP permanente que mantiene una conexión WebSocket con Pusher. Su función es recibir comandos enviados desde la UI de producción (`app.hierrospacoreyes.es`) y ejecutar la sincronización en el Windows local, donde FerraWin es accesible.

**Sin el listener activo, los botones de sincronización de la UI de producción no tienen efecto.**

### Flujo de control remoto

```
Usuario pulsa "Sincronizar" en producción
        │
        ▼
  Laravel emite SyncCommandEvent
        │
        ▼
  Pusher WebSocket (private-sync-control)
        │
        ▼
  sync-listener.php (Windows local)
        │
        ▼
  Ejecuta sync-optimizado.php en background
        │
        ▼
  Envía estado cada 10s → /api/ferrawin/sync-status
        │
        ▼
  UI de producción muestra progreso en tiempo real
```

### Cómo arrancarlo

**Opción A — Con ventana visible (para debug):**
```batch
start-listener.bat
```
Muestra logs en tiempo real. Se cierra con `Ctrl+C`.

**Opción B — En segundo plano (recomendado para uso diario):**
```batch
start-listener-background.vbs
```
Sin ventana visible. Para detenerlo: `stop-listener.bat` o Administrador de Tareas → buscar `php.exe`.

### Arranque automático al iniciar Windows (sin ejecutarlo manualmente)

Para que el listener arranque solo cada vez que se enciende el ordenador, instala la tarea programada ejecutando **una sola vez** como Administrador:

```powershell
powershell -ExecutionPolicy Bypass -File "C:\xampp\htdocs\ferrawin-sync\install-scheduled-task.ps1"
```

Esto registra la tarea `FerrawinSyncListener` en el Programador de Tareas de Windows con estas propiedades:
- **Desencadenador:** Al iniciar Windows
- **Acción:** Ejecuta `start-listener-background.vbs` (sin ventana)
- **Reinicio automático:** Si cae, lo reinicia cada 2 minutos indefinidamente (sin límite de intentos)
- **Sin timeout:** El proceso puede correr días sin que Windows lo mate

Para arrancar la tarea ahora sin reiniciar:
```batch
schtasks /run /tn "FerrawinSyncListener"
```

Para verificar que está corriendo:
```batch
schtasks /query /tn "FerrawinSyncListener" /fo LIST
```

Para desinstalar la tarea:
```batch
schtasks /delete /tn "FerrawinSyncListener" /f
```

### Múltiples ordenadores — Modo Standby

Si varios ordenadores tienen el listener instalado, **solo uno puede estar activo a la vez** (el primero que arranque). Los demás entran automáticamente en **modo standby**: esperan en segundo plano y toman el relevo solos si el primario cae.

```
PC1 arranca → activo (conectado a Pusher)
PC2 arranca → [STANDBY] Sigue activo: PC1 — reintentando en 30s
PC3 arranca → [STANDBY] Sigue activo: PC1 — reintentando en 30s

PC1 se apaga →
PC2          → [STANDBY] Red libre — tomando el relevo  ✓
PC3          → [STANDBY] Sigue activo: PC2 — reintentando en 30s
```

El standby consulta Pusher cada 30 segundos. Cuando el canal de presencia queda vacío, el siguiente PC en orden de arranque se convierte en el nuevo listener activo. No requiere intervención humana.

Para forzar el arranque ignorando el standby (útil para debug):
```batch
php sync-listener.php --force
```

---

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

## Automatización (Tareas Programadas)

El sistema tiene dos procesos automáticos independientes:

| Proceso | Cuándo | Qué hace |
|---------|--------|----------|
| `FerrawinSyncListener` | Al arrancar Windows | Mantiene el listener WebSocket activo para control remoto |
| `FerrawinSync` | Diariamente a las 14:00 | Ejecuta `sync-ferrawin.bat` (sincronización programada) |

### Instalar el listener automático

Ver sección [El Listener](#el-listener--control-remoto-desde-producción) arriba. En resumen:

```powershell
# Ejecutar como Administrador (una sola vez)
powershell -ExecutionPolicy Bypass -File "C:\xampp\htdocs\ferrawin-sync\install-scheduled-task.ps1"
```

### Instalar la sincronización diaria

```batch
# Ejecutar como Administrador (una sola vez)
create-task.bat
```

Crea la tarea `FerrawinSync` que ejecuta `sync-ferrawin.bat` todos los días a las 14:00.

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
- Verificar certificado SSL (`ApiClient.php` busca `cacert.pem` en varias rutas automáticamente)

### El listener arranca y se cierra inmediatamente
Indica que otro listener está activo en la red. Es el comportamiento correcto — entra en standby.
Comprueba con:
```batch
schtasks /query /tn "FerrawinSyncListener" /fo LIST
```
El estado será `Listo` (el VBScript terminó) pero el proceso PHP sigue corriendo. Verifica `listener.status`:
```batch
type C:\xampp\htdocs\ferrawin-sync\listener.status
```
Si el estado es `standby`, todo funciona correctamente.

### El listener no toma el relevo tras caer el primario
El standby consulta cada 30 segundos. Espera hasta 30s. Si tras ese tiempo sigue sin activarse, verifica que el proceso PHP de standby sigue vivo:
```batch
tasklist | findstr php
```

### Timeout en batch grande
- Usar `--test 5` para probar con pocas planillas
- Verificar conexión de red estable

### Planillas no aparecen
- Verificar que tienen elementos en ORD_BAR
- Verificar año correcto (ZCONTA)
- Usar `check_datos.php` para debug

---

## Historial de Cambios

### 2026-05-18
- **fix:** `sync-listener.php` — corregido crash `TypeError: json_decode() expects string, array given` en 7 puntos del handler de eventos WebSocket. Pusher/Ratchet a veces entrega `data` ya parseado como array; se añade guardia `is_string()` en todos los casos.
- **feat:** `sync-listener.php` — modo standby para múltiples ordenadores. En lugar de terminar con error al detectar otro listener activo, el proceso espera en segundo plano y toma el relevo automáticamente cuando el primario cae (polling cada 30s al canal de presencia Pusher).
- **feat:** `install-scheduled-task.ps1` — Task Scheduler configurado sin límite de reintentos (`RestartCount 99`, `ExecutionTimeLimit 0`). El listener se relanza indefinidamente si cae, sin esperar al próximo reinicio de Windows.
- **fix:** `src/ApiClient.php` — detección automática de `cacert.pem` para SSL en múltiples rutas (`php/`, raíz del proyecto, `php_drivers/`).

### 2026-01-12
- Filtro por ZCONTA en lugar de YEAR(ZFECHA)
- Soporte multi-target (local/production)
- Sistema de pausa/reanudación
- Envío en batches de 10 planillas
