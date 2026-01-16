<?php
/**
 * Sincronización optimizada - Solo procesa planillas CON DATOS
 *
 * Uso:
 *   php sync-optimizado.php --anio=2024 --target=local         # Sincronizar año específico
 *   php sync-optimizado.php --anio=2024 --target=production    # Sincronizar a producción
 *   php sync-optimizado.php --todos --target=production        # Sincronizar TODAS las planillas
 *   php sync-optimizado.php --nuevas --target=production       # Solo planillas NUEVAS (no sincronizadas)
 *   php sync-optimizado.php --test=10 --target=local           # Test con límite
 *   php sync-optimizado.php --anio=2025 --desde-codigo=2025-007816 --target=local  # Continuar desde código
 *
 * Nota: Se usa --anio en vez de --año para evitar problemas de encoding en Windows.
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

$opciones = getopt('', ['anio::', 'test::', 'todos', 'nuevas', 'dry-run', 'desde-codigo::', 'target::']);

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
$año = $opciones['anio'] ?? null;
$limite = isset($opciones['test']) ? (int)$opciones['test'] : null;
$todos = isset($opciones['todos']);
$nuevas = isset($opciones['nuevas']);
$dryRun = isset($opciones['dry-run']);
$desdeCodigo = $opciones['desde-codigo'] ?? null;

$whereAño = "";
if ($año) {
    // Filtrar por ZCONTA (año contable que forma parte del código de planilla)
    $whereAño = "AND oh.ZCONTA = '{$año}'";
} elseif (!$todos && !$nuevas) {
    // Por defecto, últimos 2 años (excepto si es --todos o --nuevas)
    $whereAño = "AND oh.ZFECHA >= DATEADD(year, -2, GETDATE())";
}

// Filtro para continuar desde un código específico
$whereDesdeCodigo = "";
if ($desdeCodigo) {
    $desdeCodigo = str_replace("'", "''", $desdeCodigo); // Escapar
    $whereDesdeCodigo = "AND (oh.ZCONTA + '-' + oh.ZCODIGO) <= '{$desdeCodigo}'";
    Logger::info("Continuando desde código: {$desdeCodigo}");
}

// Construir WHERE dinámicamente
$whereClauses = [];
if ($whereAño) {
    $whereClauses[] = ltrim($whereAño, 'AND ');
}
if ($whereDesdeCodigo) {
    $whereClauses[] = ltrim($whereDesdeCodigo, 'AND ');
}
$whereSQL = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$sql = "
    SELECT DISTINCT
        oh.ZCONTA + '-' + oh.ZCODIGO as codigo
    FROM ORD_HEAD oh
    {$whereSQL}
    ORDER BY codigo DESC
";

$modo = $nuevas ? 'nuevas' : ($año ?: 'todos');
Logger::info("Buscando planillas...", ['modo' => $modo, 'limite' => $limite ?: 'sin límite']);
Logger::info("Ejecutando consulta SQL...");

try {
    $stmt = $pdo->query($sql);
    $codigos = [];
    while ($row = $stmt->fetch()) {
        $codigos[] = $row->codigo;
    }
    Logger::info("Consulta SQL completada");
} catch (Exception $e) {
    Logger::error("Error en consulta SQL: " . $e->getMessage());
    exit(1);
}

$totalFerrawin = count($codigos);
Logger::info("Encontradas {$totalFerrawin} planillas en FerraWin");

// Si es modo "nuevas", filtrar las que ya existen en destino
if ($nuevas) {
    Logger::info("Obteniendo códigos existentes en {$target}...");
    try {
        $codigosExistentes = $apiClient->getCodigosExistentes();
        $totalExistentes = count($codigosExistentes);
        Logger::info("Planillas ya sincronizadas: {$totalExistentes}");

        // Filtrar solo las nuevas
        Logger::info("Filtrando planillas nuevas...");
        $codigosExistentesSet = array_flip($codigosExistentes);
        $codigos = array_filter($codigos, fn($c) => !isset($codigosExistentesSet[$c]));
        $codigos = array_values($codigos); // Re-indexar

        $nuevasPlanillas = count($codigos);
        Logger::info("Planillas nuevas a sincronizar: {$nuevasPlanillas}");
    } catch (Exception $e) {
        Logger::error("Error obteniendo códigos existentes: " . $e->getMessage());
        exit(1);
    }
}

// Aplicar límite si es test
if ($limite && count($codigos) > $limite) {
    $codigos = array_slice($codigos, 0, $limite);
}

$total = count($codigos);
Logger::info("Planillas a procesar: {$total}");

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
$batchSize = 5; // Reducido de 10 para evitar timeouts con planillas grandes
$batch = [];
$batchesFallidos = []; // Cola de batches fallidos para reintentar al final
$maxReintentos = 3;    // Reintentos inmediatos por batch
$delayBase = 5;        // Segundos base para backoff exponencial

foreach ($codigos as $i => $codigo) {
    // Verificar si se solicitó pausar
    if (debePausar()) {
        $añoPlanilla = substr($codigo, 0, 4);
        Logger::info("⏸️ PAUSADO por el usuario en planilla: {$codigo}");
        Logger::info("Para continuar: php sync-optimizado.php --anio {$añoPlanilla} --desde-codigo {$codigo}");

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
            $resultado = $apiClient->enviarPlanillasConRetry($batch, $maxReintentos, $delayBase);

            if ($resultado['success'] ?? false) {
                $procesadas += count($batch);
                Logger::info("Batch OK: " . count($batch) . " planillas");
            } else {
                $error = $resultado['error'] ?? 'Error desconocido';
                Logger::error("Error en batch (guardado para reintento final): {$error}");
                // Guardar batch fallido para reintentar al final
                $batchesFallidos[] = [
                    'planillas' => $batch,
                    'error' => $error,
                    'codigos' => array_column($batch, 'codigo'),
                ];
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
    $resultado = $apiClient->enviarPlanillasConRetry($batch, $maxReintentos, $delayBase);

    if ($resultado['success'] ?? false) {
        $procesadas += count($batch);
        Logger::info("Batch final OK");
    } else {
        $error = $resultado['error'] ?? 'Error desconocido';
        Logger::error("Error en batch final (guardado para reintento): {$error}");
        $batchesFallidos[] = [
            'planillas' => $batch,
            'error' => $error,
            'codigos' => array_column($batch, 'codigo'),
        ];
    }
}

// === PASADA FINAL: Reintentar batches fallidos ===
if (!empty($batchesFallidos)) {
    $totalFallidos = count($batchesFallidos);
    $planillasFallidas = array_sum(array_map(fn($b) => count($b['planillas']), $batchesFallidos));

    Logger::info("=== PASADA FINAL: Reintentando {$totalFallidos} batches fallidos ({$planillasFallidas} planillas) ===");

    // Esperar un poco antes de reintentar (el servidor puede haberse recuperado)
    Logger::info("Esperando 30 segundos antes de reintentar...");
    sleep(30);

    $recuperados = 0;
    $fallidosDefinitivos = [];

    foreach ($batchesFallidos as $idx => $batchFallido) {
        $numBatch = $idx + 1;
        $codigosBatch = implode(', ', array_slice($batchFallido['codigos'], 0, 3));
        if (count($batchFallido['codigos']) > 3) {
            $codigosBatch .= '...';
        }

        Logger::info("[Reintento final {$numBatch}/{$totalFallidos}] Batch: {$codigosBatch}");

        // Reintentar con más paciencia (más reintentos, más delay)
        $resultado = $apiClient->enviarPlanillasConRetry(
            $batchFallido['planillas'],
            $maxReintentos + 2,  // 2 reintentos extra
            $delayBase * 2       // Doble delay base
        );

        if ($resultado['success'] ?? false) {
            $recuperados += count($batchFallido['planillas']);
            $procesadas += count($batchFallido['planillas']);
            Logger::info("Batch recuperado exitosamente");
        } else {
            $errores += count($batchFallido['planillas']);
            $fallidosDefinitivos[] = $batchFallido['codigos'];
            Logger::error("Batch fallido definitivamente", [
                'codigos' => $batchFallido['codigos'],
            ]);
        }
    }

    if ($recuperados > 0) {
        Logger::info("Recuperadas {$recuperados} planillas en pasada final");
    }

    if (!empty($fallidosDefinitivos)) {
        Logger::error("=== PLANILLAS NO SINCRONIZADAS ===");
        foreach ($fallidosDefinitivos as $codigos) {
            Logger::error("  - " . implode(', ', $codigos));
        }
    }
}

$recuperadosFinal = $recuperados ?? 0;

Logger::info("=== Sincronización completada ===", [
    'total' => $total,
    'procesadas' => $procesadas,
    'vacias' => $vacias,
    'errores' => $errores,
    'recuperados_pasada_final' => $recuperadosFinal,
]);

echo "\n";
echo "=================================\n";
echo "RESUMEN\n";
echo "=================================\n";
echo "Total planillas:   {$total}\n";
echo "Procesadas OK:     {$procesadas}\n";
if ($recuperadosFinal > 0) {
    echo "  (recuperadas):   {$recuperadosFinal}\n";
}
echo "Vacías:            {$vacias}\n";
echo "Errores:           {$errores}\n";
echo "=================================\n";

if (!empty($fallidosDefinitivos ?? [])) {
    echo "\n⚠️  PLANILLAS NO SINCRONIZADAS:\n";
    foreach ($fallidosDefinitivos as $codigos) {
        echo "   - " . implode(', ', $codigos) . "\n";
    }
    echo "\n";
}
