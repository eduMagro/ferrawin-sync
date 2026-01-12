<?php

/**
 * Script principal de sincronización FerraWin → Manager Producción.
 *
 * Uso:
 *   php sync.php                              # Últimos 7 días (por defecto)
 *   php sync.php 30                           # Últimos 30 días
 *   php sync.php --test 5                     # Modo prueba: solo 5 planillas
 *   php sync.php --año 2023                   # Todo el año 2023
 *   php sync.php --desde 2023-01-01 --hasta 2023-06-30   # Rango específico
 *   php sync.php --stats                      # Ver estadísticas de FerraWin
 *   php sync.php --help                       # Mostrar ayuda
 */

require_once __DIR__ . '/vendor/autoload.php';

use FerrawinSync\Config;
use FerrawinSync\Logger;
use FerrawinSync\Database;
use FerrawinSync\FerrawinQuery;
use FerrawinSync\ApiClient;

// Archivos de control
define('PID_FILE', __DIR__ . '/sync.pid');
define('PAUSE_FILE', __DIR__ . '/sync.pause');

// Cargar configuración
Config::load();

/**
 * Guarda el PID del proceso actual para poder pausarlo.
 */
function guardarPid(): void
{
    file_put_contents(PID_FILE, getmypid());
}

/**
 * Limpia el archivo PID al terminar.
 */
function limpiarPid(): void
{
    if (file_exists(PID_FILE)) {
        unlink(PID_FILE);
    }
    // También limpiar archivo de pausa si existe
    if (file_exists(PAUSE_FILE)) {
        unlink(PAUSE_FILE);
    }
}

/**
 * Verifica si se solicitó pausar la sincronización.
 */
function debePausar(): bool
{
    return file_exists(PAUSE_FILE);
}

// Registrar limpieza al terminar
register_shutdown_function('limpiarPid');

// Guardar PID al iniciar
guardarPid();

// Parsear argumentos de línea de comandos
$opciones = parsearArgumentos($argv);

// Mostrar ayuda
if ($opciones['help']) {
    mostrarAyuda();
    exit(0);
}

// Modo estadísticas
if ($opciones['stats']) {
    mostrarEstadisticas();
    exit(0);
}

Logger::info("=== Iniciando sincronización FerraWin ===");

if ($opciones['test']) {
    Logger::info("*** MODO PRUEBA: Limitado a {$opciones['limite']} planillas ***");
}

$startTime = microtime(true);

try {
    // 1. Verificar conexión a FerraWin
    Logger::info("Verificando conexión a FerraWin...");

    if (!Database::testConnection()) {
        throw new Exception("No se pudo conectar a la base de datos FerraWin");
    }

    Logger::info("Conexión a FerraWin OK");

    // 2. Verificar conexión a producción (solo si no es modo dry-run)
    if (!$opciones['dry_run']) {
        Logger::info("Verificando conexión a producción...");

        $apiClient = new ApiClient();

        if (!$apiClient->testConnection()) {
            throw new Exception("No se pudo conectar al servidor de producción");
        }

        Logger::info("Conexión a producción OK");
    } else {
        Logger::info("*** MODO DRY-RUN: No se enviarán datos a producción ***");
        $apiClient = null;
    }

    // 3. Obtener códigos de planillas
    $queryOpciones = construirOpcionesQuery($opciones);

    Logger::info("Buscando planillas...", $queryOpciones);

    $codigos = FerrawinQuery::getCodigosPlanillas($queryOpciones);

    if (empty($codigos)) {
        Logger::info("No hay planillas para sincronizar");
        exit(0);
    }

    Logger::info("Encontradas " . count($codigos) . " planillas");

    // 4. Recopilar todas las planillas
    $planillasParaEnviar = [];
    $totalElementos = 0;

    foreach ($codigos as $index => $codigo) {
        // Verificar si se solicitó pausar
        if (debePausar()) {
            // Extraer año del código de planilla (formato: YYYY-XXXXXX)
            $añoPlanilla = substr($codigo, 0, 4);
            Logger::info("⏸️ Sincronización PAUSADA por el usuario en planilla: {$codigo}");
            Logger::info("Para continuar, ejecuta: php sync.php --año {$añoPlanilla} --desde-codigo {$codigo}");
            Database::close();
            exit(0);
        }

        $progreso = ($index + 1) . "/" . count($codigos);
        Logger::debug("[{$progreso}] Procesando planilla: {$codigo}");

        try {
            // Obtener datos de la planilla
            $datos = FerrawinQuery::getDatosPlanilla($codigo);

            if (empty($datos)) {
                Logger::warning("Planilla vacía: {$codigo}");
                continue;
            }

            // Formatear para API (incluye entidades/ensamblajes)
            $planillaFormateada = FerrawinQuery::formatearParaApiConEnsamblajes($datos, $codigo);

            if (empty($planillaFormateada)) {
                Logger::warning("No se pudo formatear planilla: {$codigo}");
                continue;
            }

            $planillasParaEnviar[] = $planillaFormateada;
            $totalElementos += count($planillaFormateada['elementos'] ?? []);
            $totalEntidades = ($totalEntidades ?? 0) + count($planillaFormateada['entidades'] ?? []);

        } catch (Throwable $e) {
            Logger::error("Excepción procesando {$codigo}: {$e->getMessage()}");
        }
    }

    if (empty($planillasParaEnviar)) {
        Logger::info("No hay planillas válidas para enviar");
        exit(0);
    }

    Logger::info("Preparadas " . count($planillasParaEnviar) . " planillas con {$totalElementos} elementos y {$totalEntidades} entidades");

    // 5. Enviar todas las planillas a producción
    if ($opciones['dry_run']) {
        Logger::info("=== DRY-RUN completado ===");
        Logger::info("Se habrían enviado " . count($planillasParaEnviar) . " planillas con {$totalElementos} elementos y {$totalEntidades} entidades");

        // Mostrar resumen de las planillas
        foreach ($planillasParaEnviar as $p) {
            $numEntidades = count($p['entidades'] ?? []);
            Logger::info("  - {$p['codigo']}: " . count($p['elementos']) . " elementos, {$numEntidades} entidades");
        }

        $duration = round(microtime(true) - $startTime, 2);
        Logger::info("Duración: {$duration}s");

        Database::close();
        exit(0);
    }

    Logger::info("Enviando planillas a producción...");

    $metadata = [
        'origen' => gethostname(),
        'fecha_sync' => date('Y-m-d H:i:s'),
        'modo' => $opciones['test'] ? 'test' : 'normal',
        'opciones' => $queryOpciones,
    ];

    $resultado = $apiClient->enviarPlanillas($planillasParaEnviar, $metadata);

    // 6. Resumen final
    $duration = round(microtime(true) - $startTime, 2);

    if ($resultado['success']) {
        $data = $resultado['data']['data'] ?? [];

        Logger::info("=== Sincronización completada ===", [
            'duracion_segundos' => $duration,
            'planillas_enviadas' => count($planillasParaEnviar),
            'planillas_creadas' => $data['planillas_creadas'] ?? 0,
            'planillas_actualizadas' => $data['planillas_actualizadas'] ?? 0,
            'elementos_creados' => $data['elementos_creados'] ?? 0,
            'entidades_creadas' => $data['entidades_creadas'] ?? 0,
        ]);

        Database::close();
        exit(0);

    } else {
        Logger::error("=== Sincronización fallida ===", [
            'duracion_segundos' => $duration,
            'error' => $resultado['error'],
        ]);

        Database::close();
        exit(1);
    }

} catch (Throwable $e) {
    Logger::error("Error fatal: {$e->getMessage()}", [
        'trace' => $e->getTraceAsString(),
    ]);

    Database::close();
    exit(1);
}

// ============================================================================
// FUNCIONES AUXILIARES
// ============================================================================

/**
 * Parsea los argumentos de línea de comandos.
 */
function parsearArgumentos(array $argv): array
{
    $opciones = [
        'dias_atras' => Config::get('sync.dias_atras', 7),
        'test' => false,
        'limite' => null,
        'año' => null,
        'fecha_desde' => null,
        'fecha_hasta' => null,
        'desde_codigo' => null,  // Para continuar desde una planilla específica
        'dry_run' => false,
        'stats' => false,
        'help' => false,
    ];

    $i = 1;
    while ($i < count($argv)) {
        $arg = $argv[$i];

        switch ($arg) {
            case '--test':
            case '-t':
                $opciones['test'] = true;
                $opciones['limite'] = isset($argv[$i + 1]) && is_numeric($argv[$i + 1])
                    ? (int)$argv[++$i]
                    : 5;
                break;

            case '--año':
            case '--year':
            case '-y':
                $opciones['año'] = isset($argv[$i + 1]) ? (int)$argv[++$i] : date('Y');
                break;

            case '--desde':
            case '--from':
                $opciones['fecha_desde'] = $argv[++$i] ?? null;
                break;

            case '--hasta':
            case '--to':
                $opciones['fecha_hasta'] = $argv[++$i] ?? null;
                break;

            case '--desde-codigo':
            case '--from-code':
            case '-c':
                $opciones['desde_codigo'] = $argv[++$i] ?? null;
                break;

            case '--dry-run':
            case '-d':
                $opciones['dry_run'] = true;
                break;

            case '--stats':
            case '-s':
                $opciones['stats'] = true;
                break;

            case '--help':
            case '-h':
                $opciones['help'] = true;
                break;

            default:
                // Si es un número, es días atrás (compatibilidad)
                if (is_numeric($arg)) {
                    $opciones['dias_atras'] = (int)$arg;
                }
                break;
        }

        $i++;
    }

    return $opciones;
}

/**
 * Construye las opciones para la query según los argumentos.
 */
function construirOpcionesQuery(array $opciones): array
{
    $queryOpciones = [];

    if ($opciones['año']) {
        $queryOpciones['año'] = $opciones['año'];
    } elseif ($opciones['fecha_desde']) {
        $queryOpciones['fecha_desde'] = $opciones['fecha_desde'];
        $queryOpciones['fecha_hasta'] = $opciones['fecha_hasta'] ?? date('Y-m-d');
    } else {
        $queryOpciones['dias_atras'] = $opciones['dias_atras'];
    }

    if ($opciones['limite']) {
        $queryOpciones['limite'] = $opciones['limite'];
    }

    // Opción para continuar desde un código específico
    if ($opciones['desde_codigo']) {
        $queryOpciones['desde_codigo'] = $opciones['desde_codigo'];
    }

    return $queryOpciones;
}

/**
 * Muestra las estadísticas de FerraWin.
 */
function mostrarEstadisticas(): void
{
    echo "\n=== ESTADÍSTICAS DE FERRAWIN ===\n\n";

    try {
        if (!Database::testConnection()) {
            echo "ERROR: No se pudo conectar a FerraWin\n";
            return;
        }

        $stats = FerrawinQuery::getEstadisticas();

        echo "Total planillas:  {$stats['total_planillas']}\n";
        echo "Total elementos:  {$stats['total_elementos']}\n";
        echo "Primera planilla: {$stats['fecha_primera']}\n";
        echo "Última planilla:  {$stats['fecha_ultima']}\n";

        echo "\n--- PLANILLAS POR AÑO ---\n";
        foreach ($stats['por_año'] as $row) {
            $año = $row->año ?? 'NULL';
            $planillas = $row->planillas;
            echo "  {$año}: {$planillas} planillas\n";
        }

        echo "\n--- RECOMENDACIÓN DE MIGRACIÓN ---\n";
        echo "Para migrar los datos históricos, ejecuta año por año:\n";
        foreach ($stats['por_año'] as $row) {
            if ($row->año) {
                echo "  php sync.php --año {$row->año}\n";
            }
        }

        echo "\n";

    } catch (Throwable $e) {
        echo "ERROR: {$e->getMessage()}\n";
    }

    Database::close();
}

/**
 * Muestra la ayuda del script.
 */
function mostrarAyuda(): void
{
    echo <<<HELP

FERRAWIN SYNC - Sincronización de planillas
============================================

USO:
  php sync.php [opciones]

OPCIONES:
  (sin argumentos)          Sincroniza últimos 7 días (por defecto)
  <número>                  Sincroniza últimos N días
                            Ejemplo: php sync.php 30

  --test, -t [N]            Modo prueba: limita a N planillas (default: 5)
                            Ejemplo: php sync.php --test 10

  --año, -y <YYYY>          Sincroniza un año específico
                            Ejemplo: php sync.php --año 2023

  --desde <FECHA>           Fecha inicio del rango (YYYY-MM-DD)
  --hasta <FECHA>           Fecha fin del rango (YYYY-MM-DD)
                            Ejemplo: php sync.php --desde 2023-01-01 --hasta 2023-06-30

  --desde-codigo, -c <COD>  Continuar desde un código de planilla específico
                            Útil para retomar sincronizaciones interrumpidas
                            Ejemplo: php sync.php --año 2025 --desde-codigo 2025-007816

  --dry-run, -d             Simula la sincronización sin enviar datos
                            Útil para ver qué se enviaría

  --stats, -s               Muestra estadísticas de FerraWin
                            Útil para planificar la migración

  --help, -h                Muestra esta ayuda

EJEMPLOS:
  php sync.php --stats                    # Ver cuántos datos hay
  php sync.php --test 3 --dry-run         # Probar con 3 planillas sin enviar
  php sync.php --test 5                   # Probar enviando 5 planillas
  php sync.php --año 2022                 # Migrar todo el año 2022
  php sync.php                            # Sincronización normal (últimos 7 días)

ESTRATEGIA DE MIGRACIÓN RECOMENDADA:
  1. php sync.php --stats                 # Ver volumen de datos
  2. php sync.php --test 3 --dry-run      # Verificar conexiones
  3. php sync.php --test 5                # Prueba real con pocas planillas
  4. php sync.php --año 2020              # Migrar año por año
  5. php sync.php --año 2021
  6. ...
  7. php sync.php                         # Configurar sincronización diaria


HELP;
}
