<?php
/**
 * Sincronización optimizada - Solo procesa planillas CON DATOS
 *
 * Uso:
 *   php sync-optimizado.php --anio=2024 --target=local         # Sincronizar año específico
 *   php sync-optimizado.php --anio=2024 --target=production    # Sincronizar a producción
 *   php sync-optimizado.php --todos --target=production        # Sincronizar TODAS las planillas
 *   php sync-optimizado.php --nuevas --target=production       # Solo planillas NUEVAS (no sincronizadas)
 *   php sync-optimizado.php --codigo=2026-000886 --target=local # Sincronizar UNA planilla específica
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

$inicioGlobal = microtime(true);

// Archivos de control para pausa
define('PID_FILE', __DIR__ . '/sync.pid');
define('PAUSE_FILE', __DIR__ . '/sync.pause');

/**
 * Verifica si un proceso con el PID dado está corriendo.
 */
function procesoExiste(int $pid): bool {
    if (PHP_OS_FAMILY === 'Windows') {
        exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
        foreach ($output as $line) {
            if (strpos($line, (string)$pid) !== false) {
                return true;
            }
        }
        return false;
    } else {
        return file_exists("/proc/{$pid}");
    }
}

/**
 * Limpia archivos de control huérfanos (de ejecuciones anteriores que no terminaron bien).
 */
function limpiarArchivosHuerfanos(): void {
    // Verificar si hay un PID guardado de un proceso que ya no existe
    if (file_exists(PID_FILE)) {
        $pidGuardado = (int) trim(file_get_contents(PID_FILE));
        if ($pidGuardado > 0 && !procesoExiste($pidGuardado)) {
            unlink(PID_FILE);
            // Si el proceso no existe, también limpiamos el archivo de pausa
            if (file_exists(PAUSE_FILE)) {
                unlink(PAUSE_FILE);
            }
        } elseif ($pidGuardado > 0 && procesoExiste($pidGuardado)) {
            // Ya hay una sincronización corriendo
            echo "ERROR: Ya hay una sincronización en ejecución (PID: {$pidGuardado})\n";
            exit(1);
        }
    }

    // Limpiar archivo de pausa huérfano (más de 1 hora sin proceso activo)
    if (file_exists(PAUSE_FILE) && !file_exists(PID_FILE)) {
        $pauseTime = filemtime(PAUSE_FILE);
        if (time() - $pauseTime > 3600) {
            unlink(PAUSE_FILE);
        }
    }
}

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

// Limpiar archivos huérfanos antes de iniciar
limpiarArchivosHuerfanos();

// Registrar limpieza al terminar y guardar PID
register_shutdown_function('limpiarPid');
guardarPid();

Config::load();

$opciones = getopt('', ['anio::', 'test::', 'todos', 'nuevas', 'dry-run', 'desde-codigo::', 'target::', 'codigo::', 'rebuild']);

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
$codigoEspecifico = $opciones['codigo'] ?? null;
$rebuild = isset($opciones['rebuild']);
$syncMetadata = ['modo' => $rebuild ? 'rebuild' : 'incremental'];

// Si se especifica un código específico, solo sincronizar esa planilla
if ($codigoEspecifico) {
    Logger::info("=== Sincronizando planilla específica: {$codigoEspecifico} ===");
    $codigos = [$codigoEspecifico];
    $totalFerrawin = 1;
} else {
    // Lógica normal: buscar planillas según filtros
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
}

// Si es modo "nuevas", filtrar las que ya existen en destino Y detectar modificadas
if ($nuevas) {
    Logger::info("Obteniendo códigos existentes en {$target} (con conteo de elementos)...");
    try {
        // Obtener códigos CON conteo de elementos para detectar cambios
        $planillasExistentes = $apiClient->getCodigosConConteo();
        $totalExistentes = count($planillasExistentes);
        Logger::info("Planillas ya sincronizadas: {$totalExistentes}");

        // Separar planillas nuevas del rango actual (las que FerraWin tiene en este año/rango
        // pero Manager aún no conoce)
        $codigosNuevos = [];
        foreach ($codigos as $codigo) {
            if (!isset($planillasExistentes[$codigo])) {
                $codigosNuevos[] = $codigo;
            }
        }

        Logger::info("Planillas nuevas: " . count($codigosNuevos));

        // Detectar modificadas comparando TODAS las planillas de Manager contra FerraWin,
        // sin restricción de año. Así se detectan cambios en 2026 aunque el sync sea de 2022.
        $codigosModificados = [];
        if (!empty($planillasExistentes)) {
            Logger::info("Verificando cambios en " . count($planillasExistentes) . " planillas existentes (todos los años)...");

            // Obtener datos de FerraWin filtrando solo las que existen en Manager (O(n) PHP-side)
            $datosFerrawin = FerrawinQuery::getAllFechasCalculo(array_keys($planillasExistentes));

            foreach (array_keys($planillasExistentes) as $codigo) {
                $fechaManager    = $planillasExistentes[$codigo]['fecha_calculo'] ?? null;
                $pesoManager     = isset($planillasExistentes[$codigo]['peso_total'])
                    ? round((float) $planillasExistentes[$codigo]['peso_total'], 4)
                    : null;
                $doblesManager    = isset($planillasExistentes[$codigo]['total_dobleces'])
                    ? (int) $planillasExistentes[$codigo]['total_dobleces']
                    : null;
                $barrasManager = isset($planillasExistentes[$codigo]['total_barras'])
                    ? (int) $planillasExistentes[$codigo]['total_barras']
                    : null;

                $fechaFW   = $datosFerrawin[$codigo]['fecha_calculo'] ?? null;
                $pesoFW    = $datosFerrawin[$codigo]['peso_total'] ?? null;
                $doblesFW  = isset($datosFerrawin[$codigo]['total_dobleces'])
                    ? (int) $datosFerrawin[$codigo]['total_dobleces']
                    : null;
                $barrasFW  = isset($datosFerrawin[$codigo]['total_barras'])
                    ? (int) $datosFerrawin[$codigo]['total_barras']
                    : null;

                $fechaCambiada  = $fechaFW && $fechaFW !== $fechaManager;
                $pesoCambiado   = $pesoManager !== null && $pesoFW !== null
                    && abs($pesoFW - $pesoManager) > 0.01;
                $doblesCambiado = $doblesManager !== null && $doblesFW !== null
                    && $doblesFW !== $doblesManager;
                $barrasCambiado = $barrasManager !== null && $barrasFW !== null
                    && $barrasFW !== $barrasManager;

                if ($fechaCambiada || $pesoCambiado || $doblesCambiado || $barrasCambiado) {
                    $codigosModificados[] = $codigo;
                    if ($fechaCambiada)      $motivo = "fecha {$fechaManager}→{$fechaFW}";
                    elseif ($pesoCambiado)   $motivo = "peso {$pesoManager}→{$pesoFW}";
                    elseif ($doblesCambiado) $motivo = "dobleces {$doblesManager}→{$doblesFW}";
                    else                     $motivo = "barras {$barrasManager}→{$barrasFW}";
                    Logger::debug("  📝 {$codigo}: {$motivo} → MODIFICADA");
                }
            }

            Logger::info("Planillas modificadas detectadas: " . count($codigosModificados));
        }

        // Combinar nuevas + modificadas
        $codigos = array_merge($codigosNuevos, $codigosModificados);
        $codigos = array_values($codigos); // Re-indexar

        // Set O(1) para saber qué códigos son actualizaciones (vs nuevas importaciones)
        $codigosModificadosSet = array_flip($codigosModificados);

        $totalASincronizar = count($codigos);
        Logger::info("Total a sincronizar (nuevas + modificadas): {$totalASincronizar}");

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

// Set de modificadas para el log (puede estar vacío si no es modo --nuevas)
$codigosModificadosSet = $codigosModificadosSet ?? [];

// Procesar planillas en batches
$procesadas = 0;
$errores = 0;
$vacias = 0;
$maxPlanillasPorBatch = 5;    // Máximo planillas por batch
$maxElementosPorBatch = 200;  // Máximo elementos por batch (evita timeouts)
$batch = [];
$batchElementos = 0;          // Contador de elementos en el batch actual
$batchesFallidos = [];        // Cola de batches fallidos para reintentar al final
$maxReintentos = 3;           // Reintentos inmediatos por batch
$delayBase = 5;               // Segundos base para backoff exponencial

foreach ($codigos as $i => $codigo) {
    // Verificar si se solicitó pausar
    if (debePausar()) {
        $añoPlanilla = substr($codigo, 0, 4);
        Logger::info("⏸️ PAUSADO por el usuario en planilla: {$codigo}");
        Logger::info("Para continuar: php sync-optimizado.php --anio {$añoPlanilla} --desde-codigo {$codigo}");

        // Si hay un batch pendiente, enviarlo antes de pausar
        if (!empty($batch)) {
            Logger::info("Enviando batch pendiente antes de pausar...");
            $resultado = $apiClient->enviarPlanillas($batch, $syncMetadata);
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

        $esActualizacion = isset($codigosModificadosSet[$codigo]);
        $marcador    = $esActualizacion ? ' [ACTUALIZACIÓN]' : '';
        $cliente     = trim($planilla['nombre_cliente'] ?? '');
        $obra        = trim($planilla['nombre_obra'] ?? '');
        $infoObra    = ($cliente || $obra) ? " | {$cliente} — {$obra}" : '';
        $infoElems   = $sinElementos ? '(sin elementos)' : "({$numElementos} elementos)";

        Logger::info("{$progreso} Preparando {$codigo}{$marcador}{$infoObra} {$infoElems}");

        $batch[] = $planilla;
        $batchElementos += $numElementos;

        // Enviar batch cuando alcanza límite de planillas O elementos
        $debEnviar = count($batch) >= $maxPlanillasPorBatch || $batchElementos >= $maxElementosPorBatch;

        if ($debEnviar) {
            Logger::info("Enviando batch de " . count($batch) . " planillas ({$batchElementos} elementos)...");
            $resultado = $apiClient->enviarPlanillasConRetry($batch, $maxReintentos, $delayBase, $syncMetadata);

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
            $batchElementos = 0;
        }

    } catch (Exception $e) {
        Logger::error("{$progreso} Excepción en {$codigo}: " . $e->getMessage());
        $errores++;
    }
}

// Enviar último batch si queda algo
if (!empty($batch)) {
    Logger::info("Enviando batch final de " . count($batch) . " planillas ({$batchElementos} elementos)...");
    $resultado = $apiClient->enviarPlanillasConRetry($batch, $maxReintentos, $delayBase, $syncMetadata);

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
            $delayBase * 2,      // Doble delay base
            $syncMetadata
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

$duracionTotal = round(microtime(true) - $inicioGlobal, 1);
$resumen = "Total: {$total} | OK: {$procesadas} | Errores: {$errores} | Vacías: {$vacias} | Duración: {$duracionTotal}s";
if ($recuperadosFinal > 0) {
    $resumen .= " | Recuperadas: {$recuperadosFinal}";
}

Logger::info("=== RESUMEN: {$resumen} ===");

if (!empty($fallidosDefinitivos ?? [])) {
    foreach ($fallidosDefinitivos as $codigos) {
        Logger::error("NO SINCRONIZADA: " . implode(', ', $codigos));
    }
}

if ($errores > 0) {
    Logger::warning("=== Sincronización completada con {$errores} errores ===");
} else {
    Logger::info("=== Sincronización completada exitosamente ===");
}

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
echo "Duración:          {$duracionTotal}s\n";
echo "=================================\n";

if (!empty($fallidosDefinitivos ?? [])) {
    echo "\n⚠️  PLANILLAS NO SINCRONIZADAS:\n";
    foreach ($fallidosDefinitivos as $codigos) {
        echo "   - " . implode(', ', $codigos) . "\n";
    }
    echo "\n";
}
