<?php
/**
 * Backfill descripcion_fila para elementos existentes.
 *
 * Este script consulta FerraWin para obtener la descripcion_fila (ZSITUACION)
 * de cada elemento y la envía al manager para actualizar los elementos.
 *
 * Uso:
 *   php backfill-descripcion-fila.php [--dry-run] [--planilla=CODIGO] [--limit=N]
 */

require 'vendor/autoload.php';

use FerrawinSync\Config;
use FerrawinSync\Database;
use FerrawinSync\ApiClient;

Config::load();

// Parsear argumentos
$dryRun = in_array('--dry-run', $argv);
$planillaArg = null;
$limit = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--planilla=') === 0) {
        $planillaArg = substr($arg, 11);
    }
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    }
}

if ($dryRun) {
    echo "🔍 MODO DRY-RUN: No se harán cambios\n\n";
}

try {
    $pdo = Database::getConnection();
    $api = new ApiClient();

    // Obtener planillas a procesar
    $wherePlanilla = $planillaArg ? "AND CONCAT(oh.ZCONTA, '-', oh.ZCODIGO) = '{$planillaArg}'" : "";
    $limitClause = $limit ? "TOP {$limit}" : "";

    $sqlPlanillas = "
        SELECT {$limitClause}
            CONCAT(oh.ZCONTA, '-', oh.ZCODIGO) as codigo
        FROM ORD_HEAD oh
        WHERE oh.ZTIPOORD = 'P'
        {$wherePlanilla}
        ORDER BY oh.ZCONTA DESC, oh.ZCODIGO DESC
    ";

    $stmtPlanillas = $pdo->query($sqlPlanillas);
    $planillas = $stmtPlanillas->fetchAll(PDO::FETCH_COLUMN);

    echo "📦 Planillas a procesar: " . count($planillas) . "\n\n";

    $totalElementos = 0;
    $totalActualizados = 0;
    $errores = [];

    foreach ($planillas as $codigo) {
        list($zconta, $zcodigo) = explode('-', $codigo);

        // Obtener descripcion_fila para cada elemento de esta planilla
        $sql = "
            SELECT
                ob.ZCODLIN as fila,
                od.ZSITUACION as descripcion_fila
            FROM ORD_BAR ob
            LEFT JOIN ORD_DET od ON ob.ZCONTA = od.ZCONTA
                AND ob.ZCODIGO = od.ZCODIGO
                AND ob.ZCODLIN = od.ZCODLIN
            WHERE ob.ZCONTA = ? AND ob.ZCODIGO = ?
            ORDER BY ob.ZCODLIN
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$zconta, $zcodigo]);
        $elementos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($elementos)) {
            continue;
        }

        $totalElementos += count($elementos);

        // Preparar datos para enviar
        $data = [
            'codigo_planilla' => $codigo,
            'elementos' => array_map(function($e) {
                return [
                    'fila' => trim($e['fila']),
                    'descripcion_fila' => trim($e['descripcion_fila'] ?? ''),
                ];
            }, $elementos),
        ];

        echo "  📋 {$codigo}: " . count($elementos) . " elementos";

        if ($dryRun) {
            echo " (dry-run)\n";
            // Mostrar muestra de datos
            $muestra = array_slice($data['elementos'], 0, 3);
            foreach ($muestra as $e) {
                echo "      Fila {$e['fila']}: {$e['descripcion_fila']}\n";
            }
            if (count($data['elementos']) > 3) {
                echo "      ... y " . (count($data['elementos']) - 3) . " más\n";
            }
            continue;
        }

        // Enviar al API
        try {
            $response = $api->post('/api/ferrawin/backfill-descripcion-fila', $data);

            if ($response['success'] ?? false) {
                $actualizados = $response['actualizados'] ?? 0;
                $totalActualizados += $actualizados;
                echo " ✅ {$actualizados} actualizados\n";
            } else {
                $error = $response['message'] ?? 'Error desconocido';
                echo " ❌ {$error}\n";
                $errores[] = "{$codigo}: {$error}";
            }
        } catch (Exception $e) {
            echo " ❌ " . $e->getMessage() . "\n";
            $errores[] = "{$codigo}: " . $e->getMessage();
        }
    }

    echo "\n=== RESUMEN ===\n";
    echo "Planillas procesadas: " . count($planillas) . "\n";
    echo "Elementos encontrados: {$totalElementos}\n";

    if (!$dryRun) {
        echo "Elementos actualizados: {$totalActualizados}\n";
    }

    if (!empty($errores)) {
        echo "\n❌ Errores:\n";
        foreach ($errores as $error) {
            echo "  - {$error}\n";
        }
    }

    if ($dryRun) {
        echo "\n⚠️ Ejecuta sin --dry-run para aplicar los cambios\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
