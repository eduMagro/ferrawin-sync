<?php
/**
 * Verificación de pesos: Manager (peso_total) vs FerraWin (peso raw), por planilla.
 *
 * Cruza el peso_total de cada planilla en Manager (activo, vía /api/ferrawin/pesos-existentes)
 * contra el peso raw de FerraWin (SUM ZPESOTESTD). El ratio Manager/FerraWin = factor de
 * expansión de ensamblaje (nº de unidades fabricadas). Por eso un ratio > 1 es NORMAL.
 *
 * MARCA (flag) solo lo sospechoso:
 *   - ratio < 0.95  → Manager pesa MENOS que FerraWin raw (la expansión solo multiplica → raro).
 *   - ratio > umbral → factor de expansión implausible (posible peso inflado por bug).
 * El resto (ratio ≈1 sin ensamblaje, o ratio entero plausible) se considera correcto.
 *
 * NO es una validación exacta (la expansión por unidades hace legítimos ratios altos);
 * es un SCREENING que destaca planillas a revisar a mano.
 *
 * Uso:
 *   php verificar-pesos.php --target=production
 *   php verificar-pesos.php --target=production --umbral=200 --limit=50
 *   php verificar-pesos.php --target=local
 */

require 'vendor/autoload.php';

use FerrawinSync\Config;
use FerrawinSync\Database;
use FerrawinSync\ApiClient;
use FerrawinSync\Logger;

// --- argumentos ---
$target = 'production';
$umbral = 150.0;   // ratio por encima del cual se marca como sospechoso
$limit  = 60;      // máximo de planillas marcadas a listar
$tol    = 0.02;    // ±2% para considerar "cuadra" (ratio≈1)

for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if (preg_match('/^--target=(.+)$/', $a, $m))      $target = $m[1];
    elseif ($a === '--target' && isset($argv[$i+1]))  $target = $argv[++$i];
    elseif (preg_match('/^--umbral=([\d.]+)$/', $a, $m)) $umbral = (float) $m[1];
    elseif (preg_match('/^--limit=(\d+)$/', $a, $m))    $limit  = (int) $m[1];
}

Config::load();
Config::setTarget($target);

Logger::info("=== Verificación de pesos Manager↔FerraWin → {$target} (umbral ratio > {$umbral}) ===");

// 1. Peso raw de FerraWin por planilla
$pdo = Database::getConnection();
Logger::info("Cargando peso raw de FerraWin (SUM ZPESOTESTD por planilla)...");
$fw = [];
$st = $pdo->query("
    SELECT ob.ZCONTA + '-' + RIGHT('000000' + ob.ZCODIGO, 6) AS codigo,
           SUM(ob.ZPESOTESTD) AS peso, COUNT(*) AS filas
    FROM ORD_BAR ob GROUP BY ob.ZCONTA, ob.ZCODIGO
");
foreach ($st as $r) {
    $fw[$r->codigo] = ['peso' => round((float) $r->peso, 2), 'filas' => (int) $r->filas];
}
Logger::info("  FerraWin: " . count($fw) . " planillas");

// 2. peso_total de Manager por código
$api = new ApiClient();
$resp = $api->get('api/ferrawin/pesos-existentes');
if (!($resp['success'] ?? false) || !($resp['data']['success'] ?? false)) {
    Logger::error("No se pudo obtener pesos de Manager: " . ($resp['error'] ?? json_encode($resp['data'] ?? null)));
    exit(1);
}
$mgr = $resp['data']['pesos'] ?? [];
Logger::info("  Manager: " . count($mgr) . " planillas");

// 3. Comparar
$cuadran = 0; $expansion = 0; $ligBajo = 0; $flagBajo = []; $flagAlto = []; $sinFw = 0; $sinPesoMgr = 0;
foreach ($mgr as $codigo => $pesoMgr) {
    $pesoMgr = (float) $pesoMgr;
    if (!isset($fw[$codigo])) { $sinFw++; continue; }
    $pesoFw = $fw[$codigo]['peso'];
    if ($pesoFw <= 0) { continue; }

    // Manager sin peso = planilla importada solo-cabecera / sin elementos activos.
    // Categoría aparte (incompleta), NO es corrupción de peso → no inunda los flags.
    if ($pesoMgr <= 0.01) { $sinPesoMgr++; continue; }

    $ratio = $pesoMgr / $pesoFw;
    if (abs($ratio - 1.0) <= $tol) {
        $cuadran++;                       // ratio ≈ 1: sin expansión, cuadra
    } elseif ($ratio >= 0.5 && $ratio < (1.0 - $tol)) {
        $ligBajo++;                       // 0.5–0.98: ligeramente bajo → espiral excluido / redondeo (normal)
    } elseif ($ratio < 0.5) {
        $flagBajo[] = ['codigo' => $codigo, 'fw' => $pesoFw, 'mgr' => $pesoMgr, 'ratio' => $ratio, 'filas' => $fw[$codigo]['filas']];
    } elseif ($ratio > $umbral) {
        $flagAlto[] = ['codigo' => $codigo, 'fw' => $pesoFw, 'mgr' => $pesoMgr, 'ratio' => $ratio, 'filas' => $fw[$codigo]['filas']];
    } else {
        $expansion++;                     // 1.02–umbral: expansión de ensamblaje plausible
    }
}

usort($flagAlto, fn($a, $b) => $b['ratio'] <=> $a['ratio']);
usort($flagBajo, fn($a, $b) => $a['ratio'] <=> $b['ratio']);

echo "\n=================================\n";
echo "VERIFICACIÓN DE PESOS ({$target})\n";
echo "=================================\n";
echo "Planillas comunes comparadas: " . ($cuadran + $expansion + count($flagBajo) + count($flagAlto)) . "\n";
echo "  ✅ Cuadran (ratio≈1, sin expansión):       {$cuadran}\n";
echo "  ↗️  Expansión plausible (1 < ratio ≤ {$umbral}):   {$expansion}\n";
echo "  ◽ Ligeramente bajo (0.5–0.98, espiral/redondeo): {$ligBajo}\n";
echo "  🔴 Manager MUY por debajo (ratio < 0.5):    " . count($flagBajo) . "\n";
echo "  🔴 Ratio implausible (> {$umbral}):             " . count($flagAlto) . "\n";
echo "  ⬜ Manager sin peso (cabecera/sin elementos): {$sinPesoMgr}  (incompletas, no es corrupción)\n";
echo "  (solo en Manager, sin FerraWin: {$sinFw})\n";

$pintar = function ($titulo, $lista) use ($limit) {
    if (empty($lista)) return;
    echo "\n--- {$titulo} (top " . min($limit, count($lista)) . ") ---\n";
    printf("%-16s %14s %16s %10s %7s\n", "CODIGO", "FerraWin", "Manager", "RATIO", "FW_fil");
    foreach (array_slice($lista, 0, $limit) as $x) {
        printf("%-16s %14s %16s %10s %7s\n", $x['codigo'],
            number_format($x['fw'], 2), number_format($x['mgr'], 2), '×' . round($x['ratio'], 1), $x['filas']);
    }
};
$pintar("🔴 Manager pesa MENOS que FerraWin (revisar)", $flagBajo);
$pintar("🔴 Ratio implausiblemente alto (posible peso inflado)", $flagAlto);
echo "\n";
