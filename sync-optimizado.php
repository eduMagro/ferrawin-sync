<?php
/**
 * Sincronización optimizada - Solo procesa planillas CON DATOS
 *
 * Uso:
 *   php sync-optimizado.php --año 2024 --target local
 *   php sync-optimizado.php --año 2024 --target production
 *   php sync-optimizado.php --test 10 --target local
 *   php sync-optimizado.php --todos --target production
 *   php sync-optimizado.php --año 2025 --desde-codigo 2025-007816 --target local
 */

require 'vendor/autoload.php';

use FerrawinSync\Config;
use FerrawinSync\Database;
use FerrawinSync\FerrawinQuery;
use FerrawinSync\ApiClient;
use FerrawinSync\Logger;

// Archivos de control para pausa
define('PID_FILE', __DIR__ . '/sync.pid');
define('PAUSE_FILE', __DIR__ . '/sync.pause');

/**
 * Guarda el PID del proceso actual.
 */
function guardarPid(): void {
    file_put_contents(PID_FILE, getmypid());
}

/**
 * Limpia archivos de control al terminar.
 */
function limpiarPid(): void {
    if (file_exists(PID_FILE)) unlink(PID_FILE);
    if (file_exists(PAUSE_FILE)) unlink(PAUSE_FILE);
}

/**
 * Verifica si se solicitó pausar.
 */
function debePausar(): bool {
    return file_exists(PAUSE_FILE);
}

// Registrar limpieza al terminar y guardar PID
register_shutdown_function('limpiarPid');
guardarPid();

Config::load();

$opciones = getopt('', ['año::', 'test::', 'todos', 'dry-run', 'desde-codigo::', 'target::']);

// Configurar target (local o production)
$target = $opciones['target'] ?? 'local';
Config::setTarget($target);

$targetUrl = Config::target('url');
Logger::info("=== Sincronización Optimizada FerraWin ===");
Logger::info("Target: {$target} ({$targetUrl})");

try {
    Logger::info("Verificando conexión a FerraWin...");
    $pdo = Database::getConnection();
    Logger::info("Conexión a FerraWin OK");

    Logger::info("Verificando conexión a {$target}...");
    $apiClient = new ApiClient();
    if (!$apiClient->testConnection()) {
        throw new Exception("No se pudo conectar a {$target}: {$targetUrl}");
    }
    Logger::info("Conexión a {$target} OK");
} catch (Exception $e) {
    Logger::error("Error de conexión", ['error' => $e->getMessage()]);
    exit(1);
}

// Obtener planillas CON DATOS (INNER JOIN con ORD_BAR)
$año = $opciones['año'] ?? null;
$limite = isset($opciones['test']) ? (int)$opciones['test'] : null;
$todos = isset($opciones['todos']);
$dryRun = isset($opciones['dry-run']);
$desdeCodigo = $opciones['desde-codigo'] ?? null;

$whereAño = "";
if ($año) {
    // Filtrar por ZCONTA (año contable que forma parte del código de planilla)
    $whereAño = "AND oh.ZCONTA = '{$año}'";
} elseif (!$todos) {
    // Por defecto, últimos 2 años
    $whereAño = "AND oh.ZFECHA >= DATEADD(year, -2, GETDATE())";
}

// Filtro para continuar desde un código específico
$whereDesdeCodigo = "";
if ($desdeCodigo) {
    $desdeCodigo = str_replace("'", "''", $desdeCodigo); // Escapar
    $whereDesdeCodigo = "AND (oh.ZCONTA + '-' + oh.ZCODIGO) <= '{$desdeCodigo}'";
    Logger::info("Continuando desde código: {$desdeCodigo}");
}

$sql = "
    SELECT DISTINCT
        oh.ZCONTA + '-' + oh.ZCODIGO as codigo
    FROM ORD_HEAD oh
    WHERE oh.ZTIPOORD = 'P' {$whereAño} {$whereDesdeCodigo}
    ORDER BY codigo DESC
";

Logger::info("Buscando planillas...", ['año' => $año ?: 'todos', 'limite' => $limite ?: 'sin límite']);

$stmt = $pdo->query($sql);
$codigos = [];
while ($row = $stmt->fetch()) {
    $codigos[] = $row->codigo;
}

// Aplicar límite si es test
if ($limite && count($codigos) > $limite) {
    $codigos = array_slice($codigos, 0, $limite);
}

$total = count($codigos);
Logger::info("Encontradas {$total} planillas");

if ($dryRun) {
    echo "\n[DRY-RUN] Planillas que se sincronizarían: {$total}\n";
    foreach (array_slice($codigos, 0, 20) as $c) {
        echo "  - {$c}\n";
    }
    if ($total > 20) {
        echo "  ... y " . ($total - 20) . " más\n";
    }
    exit(0);
}

if ($total === 0) {
    Logger::info("No hay planillas para sincronizar");
    exit(0);
}

// Procesar planillas en batches
$procesadas = 0;
$errores = 0;
$vacias = 0;
$batchSize = 10;
$batch = [];

foreach ($codigos as $i => $codigo) {
    // Verificar si se solicitó pausar
    if (debePausar()) {
        $añoPlanilla = substr($codigo, 0, 4);
        Logger::info("⏸️ PAUSADO por el usuario en planilla: {$codigo}");
        Logger::info("Para continuar: php sync-optimizado.php --año {$añoPlanilla} --desde-codigo {$codigo}");

        // Si hay un batch pendiente, enviarlo antes de pausar
        if (!empty($batch)) {
            Logger::info("Enviando batch pendiente antes de pausar...");
            $resultado = $apiClient->enviarPlanillas($batch);
            if ($resultado['success'] ?? false) {
                $procesadas += count($batch);
            }
        }
        exit(0);
    }

    $progreso = sprintf("[%d/%d]", $i + 1, $total);

    try {
        $datos = FerrawinQuery::getDatosPlanilla($codigo);

        // Formatear planilla (obtiene cabecera aunque no haya elementos)
        $planilla = FerrawinQuery::formatearParaApiConEnsamblajes($datos, $codigo);

        if (empty($planilla)) {
            Logger::warning("{$progreso} Sin datos para formatear: {$codigo}");
            $vacias++;
            continue;
        }

        $numElementos = count($planilla['elementos'] ?? []);
        $sinElementos = $planilla['sin_elementos'] ?? false;

        if ($sinElementos) {
            Logger::info("{$progreso} Preparando {$codigo} (sin elementos - solo cabecera)");
        } else {
            Logger::info("{$progreso} Preparando {$codigo} ({$numElementos} elementos)");
        }

        $batch[] = $planilla;

        // Enviar batch cuando está lleno
        if (count($batch) >= $batchSize) {
            Logger::info("Enviando batch de " . count($batch) . " planillas...");
            $resultado = $apiClient->enviarPlanillas($batch);

            if ($resultado['success'] ?? false) {
                $procesadas += count($batch);
                Logger::info("Batch OK: " . count($batch) . " planillas");
            } else {
                $error = $resultado['error'] ?? 'Error desconocido';
                Logger::error("Error en batch: {$error}");
                $errores += count($batch);
            }
            $batch = [];
        }

    } catch (Exception $e) {
        Logger::error("{$progreso} Excepción en {$codigo}: " . $e->getMessage());
        $errores++;
    }
}

// Enviar último batch si queda algo
if (!empty($batch)) {
    Logger::info("Enviando batch final de " . count($batch) . " planillas...");
    $resultado = $apiClient->enviarPlanillas($batch);

    if ($resultado['success'] ?? false) {
        $procesadas += count($batch);
        Logger::info("Batch final OK");
    } else {
        $error = $resultado['error'] ?? 'Error desconocido';
        Logger::error("Error en batch final: {$error}");
        $errores += count($batch);
    }
}

Logger::info("=== Sincronización completada ===", [
    'total' => $total,
    'procesadas' => $procesadas,
    'vacias' => $vacias,
    'errores' => $errores,
]);

echo "\n";
echo "=================================\n";
echo "RESUMEN\n";
echo "=================================\n";
echo "Total planillas: {$total}\n";
echo "Procesadas OK:   {$procesadas}\n";
echo "Vacías:          {$vacias}\n";
echo "Errores:         {$errores}\n";
echo "=================================\n";
