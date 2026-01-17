# Estado del Backfill de ferrawin_id

**Fecha:** 2026-01-13
**Objetivo:** Rellenar el campo `ferrawin_id` de elementos existentes con los valores exactos de FerraWin (ZELEMENTO)

---

## Resumen

El campo `ferrawin_id` identifica de forma única cada elemento dentro de una planilla. El formato es `{fila}-{zelemento}` (ej: "1-000001"). Este campo es necesario para que las sincronizaciones futuras puedan identificar y actualizar elementos existentes en lugar de duplicarlos.

---

## Lo que se ha hecho

### 1. Endpoints API creados en Manager (LOCAL)

**Archivo:** `app/Http/Controllers/Api/FerrawinSyncController.php`

Nuevos métodos añadidos:
- `elementosParaMatching($codigo)` - Devuelve elementos de una planilla para hacer matching
- `actualizarFerrawinIds()` - Recibe actualizaciones de ferrawin_id y las aplica

**Archivo:** `routes/api.php`

Rutas añadidas:
```php
Route::get('/elementos-para-matching/{codigo}', [FerrawinSyncController::class, 'elementosParaMatching'])
    ->middleware('ferrawin.api');

Route::post('/actualizar-ferrawin-ids', [FerrawinSyncController::class, 'actualizarFerrawinIds'])
    ->middleware('ferrawin.api');
```

### 2. Script de backfill creado

**Archivo:** `C:\xampp\htdocs\ferrawin-sync\backfill-ferrawin-ids.php`

Este script:
1. Obtiene la lista de planillas existentes en la BD destino
2. Para cada planilla, obtiene los elementos de la BD y de FerraWin
3. Hace matching basándose en: fila + diametro + longitud + barras + dobles_barra + peso
4. Envía las actualizaciones de ferrawin_id a la API

**Uso:**
```bash
# Dry-run (simular sin cambios)
php backfill-ferrawin-ids.php --dry-run --target=local

# Ejecutar en local
php backfill-ferrawin-ids.php --target=local

# Ejecutar en producción
php backfill-ferrawin-ids.php --target=production

# Solo una planilla específica
php backfill-ferrawin-ids.php --planilla=2025-008634 --target=local

# Con logs detallados
php backfill-ferrawin-ids.php --target=local --verbose
```

### 3. Backfill LOCAL completado

- **Resultado:** Ejecutado correctamente
- **Planillas procesadas:** 951
- **Elementos actualizados:** La mayoría de planillas mostraron 100% matches

---

## Lo que falta por hacer

### 1. Desplegar cambios a PRODUCCIÓN

Los siguientes archivos deben subirse al servidor de producción (`https://app.hierrospacoreyes.es/`):

| Archivo | Descripción |
|---------|-------------|
| `routes/api.php` | Nuevas rutas para el backfill |
| `app/Http/Controllers/Api/FerrawinSyncController.php` | Nuevos métodos del controlador |

**Después de subir, ejecutar en producción:**
```bash
php artisan route:cache
php artisan config:cache
```

### 2. Ejecutar backfill en PRODUCCIÓN

Una vez desplegado el código:

```bash
cd C:\xampp\htdocs\ferrawin-sync

# Primero probar con dry-run
php backfill-ferrawin-ids.php --dry-run --target=production

# Si todo OK, ejecutar
php backfill-ferrawin-ids.php --target=production
```

---

## Detalles técnicos

### Algoritmo de matching

El script usa 3 niveles de matching (en orden):

1. **Match exacto:** fila + diametro + longitud + barras + dobles_barra + peso
2. **Match flexible:** fila + diametro + longitud + barras (sin peso)
3. **Match por posición:** fila + diametro + longitud (tolerancia 10cm)

### Normalización de filas

- BD almacena filas sin ceros: `'1'`, `'2'`, `'3'`
- FerraWin almacena con ceros: `'000001'`, `'000002'`
- El script normaliza ambos quitando ceros a la izquierda

### Formato ferrawin_id

```
{fila}-{zelemento}
Ejemplo: "1-000001"
```

---

## Archivos relevantes

| Ruta | Descripción |
|------|-------------|
| `C:\xampp\htdocs\ferrawin-sync\backfill-ferrawin-ids.php` | Script principal de backfill |
| `C:\xampp\htdocs\manager\routes\api.php` | Rutas API (incluye las nuevas) |
| `C:\xampp\htdocs\manager\app\Http\Controllers\Api\FerrawinSyncController.php` | Controlador con endpoints |
| `C:\xampp\htdocs\ferrawin-sync\.env` | Configuración de URLs y tokens |

---

## Error conocido

Durante el backfill local, la planilla `2025-008452` falló con:
```
Table 'bigmat.productos_base' doesn't exist
```

Este es un problema de configuración/cache en esa planilla específica, no afecta al resto. Se puede ignorar o investigar después.

---

## Próximos pasos (resumen)

1. **Desplegar** `routes/api.php` y `FerrawinSyncController.php` a producción
2. **Limpiar cache** en producción: `php artisan route:cache`
3. **Ejecutar** `php backfill-ferrawin-ids.php --target=production`
4. **Verificar** que los elementos tienen ferrawin_id poblado
