<?php
/**
 * Backfill de huellas de contenido (hash) en Manager SIN re-importar.
 *
 * Calcula el hash de TODAS las planillas desde FerraWin (un solo GROUP BY) y los manda
 * en lotes al endpoint /api/ferrawin/backfill-hashes, que solo hace upsert del hash en
 * `planilla_snapshots`. Así todas las planillas adoptan la detección por hash de golpe,
 * sin churn (no toca elementos) ni pipeline.
 *
 * Uso:
 *   php backfill-hashes.php --target=production
 *   php backfill-hashes.php --target=local --dry-run        # solo cuenta, no envía
 *   php backfill-hashes.php --target=local --batch=1000
 *
 * Recomendado lanzarlo UNA vez tras desplegar el servidor con la tabla planilla_snapshots.
 * Es idempotente: re-ejecutarlo no causa daño (el hash es el mismo).
 */

require 'vendor/autoload.php';

use FerrawinSync\Config;
use FerrawinSync\Database;
use FerrawinSync\FerrawinQuery;
use FerrawinSync\ApiClient;
use FerrawinSync\Logger;

// --- Parseo de argumentos (soporta --opcion=valor y --opcion valor) ---
$target = 'production';
$dryRun = false;
$batchSize = 2000;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (preg_match('/^--target=(.+)$/', $arg, $m)) {
        $target = $m[1];
    } elseif ($arg === '--target' && isset($argv[$i + 1])) {
        $target = $argv[++$i];
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $batchSize = max(1, (int) $m[1]);
    }
}

Config::load();
Config::setTarget($target);

Logger::info("=== Backfill de hashes → {$target} " . ($dryRun ? '(DRY-RUN)' : '') . " ===");

// Verificar conexiones
try {
    Database::getConnection();
    Logger::info("Conexión a FerraWin OK");
} catch (\Throwable $e) {
    Logger::error("No se pudo conectar a FerraWin: {$e->getMessage()}");
    exit(1);
}

$api = new ApiClient();

// 1. Calcular hashes de TODAS las planillas en FerraWin (un solo escaneo)
Logger::info("Calculando hashes de todas las planillas en FerraWin (un GROUP BY)...");
$datos = FerrawinQuery::getAllFechasCalculo(); // sin filtro = todas
Logger::info("Planillas con datos en FerraWin: " . count($datos));

// 2. Enviar en lotes
$lote = [];
$totalEnviadas = 0;
$totalActualizadas = 0;
$totalNoEncontradas = 0;
$totalSinHash = 0;
$loteNum = 0;

$flush = function () use (&$lote, &$totalEnviadas, &$totalActualizadas, &$totalNoEncontradas, &$loteNum, $api, $dryRun) {
    if (empty($lote)) {
        return;
    }
    $loteNum++;
    $n = count($lote);
    $totalEnviadas += $n;

    if ($dryRun) {
        Logger::info("  [DRY-RUN] Lote #{$loteNum}: {$n} hashes (no enviado)");
        $lote = [];
        return;
    }

    try {
        $resp = $api->enviarBackfillHashes($lote);
        $act = $resp['actualizadas'] ?? 0;
        $ne  = $resp['no_encontradas'] ?? 0;
        $totalActualizadas += $act;
        $totalNoEncontradas += $ne;
        Logger::info("  Lote #{$loteNum}: {$n} enviados → {$act} actualizadas, {$ne} no encontradas");
    } catch (\Throwable $e) {
        Logger::error("  Lote #{$loteNum} FALLÓ: {$e->getMessage()}");
    }
    $lote = [];
};

foreach ($datos as $codigo => $info) {
    $hash = $info['hash'] ?? null;
    if ($hash === null || $hash === '') {
        $totalSinHash++;
        continue;
    }
    $lote[$codigo] = $hash;
    if (count($lote) >= $batchSize) {
        $flush();
    }
}
$flush();

Logger::info("=== Backfill completado ===");
Logger::info("  Enviadas:       {$totalEnviadas}");
Logger::info("  Actualizadas:   {$totalActualizadas}");
Logger::info("  No encontradas: {$totalNoEncontradas} (existen en FerraWin pero no en Manager)");
Logger::info("  Sin hash:       {$totalSinHash} (sin filas en ORD_BAR)");

echo "\n=================================\n";
echo "BACKFILL DE HASHES\n";
echo "=================================\n";
echo "Target:          {$target}" . ($dryRun ? ' (DRY-RUN)' : '') . "\n";
echo "Enviadas:        {$totalEnviadas}\n";
echo "Actualizadas:    {$totalActualizadas}\n";
echo "No encontradas:  {$totalNoEncontradas}\n";
echo "Sin hash:        {$totalSinHash}\n";
echo "=================================\n";
