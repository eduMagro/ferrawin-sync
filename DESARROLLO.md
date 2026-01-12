# FerraWin Sync - Documentación de Desarrollo

> **Para uso general y comandos, ver [README.md](README.md)**

## Resumen del Sistema

Sistema de sincronización entre FerraWin (software de gestión de ferralla) y el Manager (aplicación web Laravel). Extrae datos de planillas, elementos y entidades/ensamblajes desde FerraWin y los envía al Manager vía API.

---

## Arquitectura

```
┌─────────────────────────────────────────────────────────────────┐
│                     RED LOCAL (Oficina)                         │
│                                                                 │
│   ┌─────────────────┐       ┌─────────────────────┐            │
│   │    FerraWin     │       │   ferrawin-sync     │            │
│   │   SQL Server    │◄──────│   (Script PHP)      │            │
│   │  192.168.0.7    │       │                     │            │
│   │                 │       │  sync-optimizado.php│            │
│   │   ORD_HEAD      │       │  src/               │            │
│   │   ORD_BAR       │       │                     │            │
│   │   ORD_DET       │       └──────────┬──────────┘            │
│   │   PROD_DETO     │                  │                        │
│   │   PROJECT       │                  │                        │
│   └─────────────────┘                  │                        │
│                                        │                        │
└────────────────────────────────────────┼────────────────────────┘
                                         │
                                         │ HTTPS POST
                                         │ /api/ferrawin/sync
                                         ▼
                            ┌─────────────────────────┐
                            │  Servidor Producción    │
                            │ app.hierrospacoreyes.es │
                            │       (Laravel)         │
                            │                         │
                            │  FerrawinBulkImport     │
                            │  Service                │
                            └─────────────────────────┘
```

---

## Tablas de FerraWin

| Tabla | Descripción | Uso |
|-------|-------------|-----|
| `ORD_HEAD` | Cabecera de planillas | Datos generales: cliente, obra, fecha |
| `ORD_BAR` | Elementos/barras individuales | Cada barra con diámetro, longitud, figura |
| `ORD_DET` | Entidades/ensamblajes | Pilares, vigas, zunchos con marca y situación |
| `PROD_DETO` | Producción detallada | Info adicional de fabricación |
| `PROJECT` | Proyectos/obras | Datos de cliente y obra |

### Campos Clave

**ORD_HEAD (Planilla)**
- `ZCONTA + ZCODIGO` = código único de planilla
- `ZFECHA` = fecha de creación
- `ZMODULO` = sección
- `ZCODOBRA` = código de obra (FK a PROJECT)

**ORD_BAR (Elementos)**
- `ZCODLIN` = línea/fila (agrupa elementos de una entidad)
- `ZMARCA` = marca descriptiva (ej: "Port1 P5-P6")
- `ZDIAMETRO` = diámetro en mm
- `ZLONGTESTD` = longitud en mm
- `ZCANTIDAD` = número de barras
- `ZNUMBEND` = número de dobleces (>0 = estribo)
- `ZFIGURA` = dimensiones del doblado

**ORD_DET (Entidades)**
- `ZCODLIN` = código de línea (relaciona con ORD_BAR.ZCODLIN)
- `ZMARCA` = marca de la entidad (ej: "P1", "V1")
- `ZSITUACION` = descripción (ej: "PILAR PLANTA BAJA")
- `ZCANTIDAD` = cantidad de unidades
- `ZMEMBERS` = miembros

---

## Estructura del Proyecto

```
ferrawin-sync/
├── sync-optimizado.php    # Script principal (RECOMENDADO)
├── sync.php               # Script antiguo (más opciones)
├── test-connection.php    # Verificar conexiones
├── check_datos.php        # Debug de planilla específica
├── src/
│   ├── ApiClient.php      # Cliente HTTP para Manager API
│   ├── Config.php         # Configuración desde .env (multi-target)
│   ├── Database.php       # Conexión SQL Server
│   ├── FerrawinQuery.php  # Queries a FerraWin (principal)
│   └── Logger.php         # Logging con Monolog
├── logs/                  # Logs diarios (sync-YYYY-MM-DD.log)
├── .env                   # Configuración (credenciales, URLs)
├── README.md              # Documentación de uso
├── DESARROLLO.md          # Documentación técnica (este archivo)
└── composer.json
```

---

## FerrawinQuery.php - Métodos Principales

### `getCodigosPlanillas(array $opciones)`
Obtiene códigos de planillas con filtros flexibles.

```php
$codigos = FerrawinQuery::getCodigosPlanillas([
    'dias_atras' => 7,        // últimos 7 días
    'año' => 2024,            // año específico
    'fecha_desde' => '2024-01-01',
    'limite' => 100,
]);
```

### `getDatosPlanilla(string $codigo)`
Obtiene elementos de una planilla con JOIN a ORD_HEAD, ORD_DET, PROJECT.

### `getComposicionEntidades(string $codigo)` ⭐ NUEVO
Obtiene entidades/ensamblajes desde ORD_DET + ORD_BAR:
- Marca, situación, cantidad
- Composición: barras y estribos separados
- Distribución calculada: armadura longitudinal/transversal
- Resumen: total barras, estribos, peso, longitud

```php
// Retorna array de entidades con esta estructura:
[
    'linea' => '100',
    'marca' => 'P1',
    'situacion' => 'PILAR PLANTA BAJA',
    'cantidad' => 2,
    'composicion' => [
        'barras' => [...],
        'estribos' => [...]
    ],
    'distribucion' => [
        'armadura_longitudinal' => [...],
        'armadura_transversal' => [...]
    ],
    'resumen' => [...]
]
```

### `formatearParaApiConEnsamblajes()`
Combina elementos + ensamblajes + entidades para enviar a la API.

---

## Manager - Modelos Relacionados

### `PlanillaEntidad`
Tabla: `planilla_entidades`

```php
// Campos
- id, planilla_id
- linea, marca, situacion, cantidad, miembros
- composicion (JSON)      // barras y estribos
- distribucion (JSON)     // armadura longitudinal/transversal
- longitud_ensamblaje, peso_total
- total_barras, total_estribos
```

### `EtiquetaEnsamblaje`
Tabla: `etiquetas_ensamblaje`

```php
// Campos
- id, codigo (ENS-P1-1-1/2)
- planilla_id, planilla_entidad_id
- numero_unidad, total_unidades
- estado: pendiente | en_proceso | completada
- operario_id
- fecha_inicio, fecha_fin
- marca, situacion, longitud, peso
- impresa, fecha_impresion
```

**Relaciones:**
- `planilla()` → BelongsTo(Planilla)
- `entidad()` → BelongsTo(PlanillaEntidad)
- `operario()` → BelongsTo(User)

---

## Flujo de Datos

### 1. Sincronización (ferrawin-sync)
```
sync.php
  └── FerrawinQuery::getCodigosPlanillas()
  └── foreach planilla:
      └── FerrawinQuery::getDatosPlanilla()
      └── FerrawinQuery::getComposicionEntidades()  ← NUEVO
      └── ApiClient::enviarPlanilla()
```

### 2. Importación (Manager)
```
FerrawinBulkImportService::importar()
  └── procesarPlanilla()
      ├── crearCliente/Obra
      ├── crearPlanilla
      ├── crearElementosBulk()
      ├── crearEntidades()  ← NUEVO
      └── asignarMaquinas()
```

### 3. Generación de Etiquetas
```
PlanillaController::show()
  └── if entidades.isNotEmpty() && etiquetasEnsamblaje.isEmpty()
      └── EtiquetaEnsamblajeService::generarParaPlanilla()
          └── foreach entidad:
              └── for i = 1 to entidad.cantidad:
                  └── crearEtiqueta(entidad, i, cantidad)
```

---

## Componente Visual: Etiqueta de Ensamblaje

**Ubicación:** `resources/views/components/entidad/ensamblaje.blade.php`

Muestra:
1. **Header**: Obra, cliente, código planilla
2. **Código etiqueta**: ENS-P1-1-1/2 (marca-id-unidad/total)
3. **Datos**: Marca, situación, longitud, peso
4. **Gráfico SVG**:
   - Sección transversal (círculos = barras, rectángulo = estribo)
   - Vista lateral (líneas horizontales = barras, verticales = estribos)
   - Soporte para zonas de solape (sin estribos)
5. **Leyenda**: A: 4⌀16mm (superior) | B: 4⌀12mm (inferior) | C: 24⌀8mm c/15cm
6. **QR Code**: Código de etiqueta

---

## Problema de Vinculación Elemento-Entidad

### Situación Actual
- **Entidad marca**: "P1", "V1", "Z1"
- **Elemento marca**: "Port1 P5-P6", "Port3 B30-B31"
- **Entidad linea**: 100, 200, 300
- **Elemento fila**: 1, 2, 3...

**No existe vinculación directa** entre entidades y elementos específicos en FerraWin.

### Solución Implementada
Las entidades contienen datos completos de composición:
```json
{
  "barras": [{"diametro": 16, "cantidad": 4, "posicion": "esquina"}],
  "estribos": [{"diametro": 8, "cantidad": 24, "tipo": "cerrado"}]
}
```

El esquema gráfico se genera desde estos datos, sin necesidad de vincular elementos específicos de la BD.

---

## Configuración

### .env (ferrawin-sync)
```env
FERRAWIN_HOST=localhost
FERRAWIN_DATABASE=FERRAWIN
FERRAWIN_USERNAME=sa
FERRAWIN_PASSWORD=xxx

MANAGER_API_URL=http://localhost/manager
MANAGER_API_TOKEN=xxx
```

### config/planillas.php (Manager)
```php
'estrategia_subetiquetas_default' => 'individual',
'estrategias_subetiquetas' => [
    'MSR20' => 'agrupada',      // Solo MSR20 agrupa
    'cortadora_dobladora' => 'individual',
    // ...
],
```

---

## Rutas API (Manager)

### Etiquetas de Ensamblaje
```
POST /etiquetas-ensamblaje/planilla/{planilla}/generar
POST /etiquetas-ensamblaje/{etiqueta}/iniciar
POST /etiquetas-ensamblaje/{etiqueta}/completar
POST /etiquetas-ensamblaje/{etiqueta}/marcar-impresa
```

---

## Archivos Clave Modificados

### ferrawin-sync
- `src/FerrawinQuery.php` - Añadido `getComposicionEntidades()`, `calcularDistribucion()`

### Manager
- `app/Models/PlanillaEntidad.php` - Modelo de entidades
- `app/Models/EtiquetaEnsamblaje.php` - Modelo de etiquetas
- `app/Services/EtiquetaEnsamblajeService.php` - Lógica de generación
- `app/Services/FerrawinSync/FerrawinBulkImportService.php` - Añadido `crearEntidades()`
- `app/Http/Controllers/EtiquetaEnsamblajeController.php` - Endpoints
- `resources/views/components/entidad/ensamblaje.blade.php` - Componente visual
- `resources/views/planillas/show.blade.php` - Integración en vista
- `config/planillas.php` - Estrategias de subetiquetas
- `database/migrations/..._create_planilla_entidades_table.php`
- `database/migrations/..._create_etiquetas_ensamblaje_table.php`

---

## Pendientes / Mejoras Futuras

1. **Vinculación elemento-entidad**: Explorar si hay campos adicionales en FerraWin que permitan vincular
2. **Dimensiones de estribo**: Extraer alto/ancho desde FerraWin si disponible
3. **Zonas de solape**: Implementar detección automática desde datos
4. **Estados de ensamblaje**: Integrar con sistema de producción
5. **Reportes**: Estadísticas de ensamblajes por obra/fecha

---

## Comandos Útiles

> **Ver [README.md](README.md) para documentación completa de comandos**

```bash
# SCRIPT RECOMENDADO: sync-optimizado.php

# Sincronizar año 2025 a producción
php sync-optimizado.php --año 2025 --target production

# Sincronizar año 2025 a local
php sync-optimizado.php --año 2025 --target local

# Continuar desde planilla específica
php sync-optimizado.php --año 2025 --desde-codigo 2025-007500 --target production

# Probar con 10 planillas
php sync-optimizado.php --test 10 --target local

# SCRIPT ANTIGUO: sync.php (para estadísticas)

# Ver estadísticas de FerraWin
php sync.php --stats

# Sincronizar por rango de fechas
php sync.php --desde 2024-01-01 --hasta 2024-06-30
```

---

## Última Actualización
**Fecha:** 2026-01-12

**Cambios recientes:**
- Nuevo script `sync-optimizado.php` con envío en batches
- Soporte multi-target (local/production) en Config.php
- Filtro por ZCONTA (año contable) en lugar de YEAR(ZFECHA)
- Sistema de pausa/reanudación con archivos de control
- Documentación actualizada (README.md + DESARROLLO.md)
- Sistema de etiquetas de ensamblaje con gráfico SVG
- Importación de entidades desde ORD_DET
- Cálculo de distribución (armadura longitudinal/transversal)
