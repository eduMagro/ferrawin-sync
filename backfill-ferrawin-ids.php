<?php
/**
 * Script para hacer matching de elementos existentes con FerraWin y actualizar ferrawin_id.
 *
 * Uso:
 *   php backfill-ferrawin-ids.php --target=local           # Actualizar en local
 *   php backfill-ferrawin-ids.php --target=production      # Actualizar en producción
 *   php backfill-ferrawin-ids.php --planilla=2025-008634   # Solo una planilla
 *   php backfill-ferrawin-ids.php --dry-run --target=local # Simular sin cambios
 */

require 'vendor/autoload.php';

use FerrawinSync\Config;
use FerrawinSync\Database;
use FerrawinSync\ApiClient;
use FerrawinSync\Logger;

Config::load();

$opciones = getopt('', ['target::', 'planilla::', 'dry-run', 'verbose']);

$target = $opciones['target'] ?? 'local';
$planillaEspecifica = $opciones['planilla'] ?? null;
$dryRun = isset($opciones['dry-run']);
$verbose = isset($opciones['verbose']);

Config::setTarget($target);

$targetUrl = Config::target('url');
Logger::info("=== Backfill de ferrawin_id desde FerraWin ===");
Logger::info("Target: {$target} ({$targetUrl})");

if ($dryRun) {
    Logger::info("MODO DRY-RUN: No se harán cambios");
}

// Verificar conexiones
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

// Obtener planillas a procesar
if ($planillaEspecifica) {
    $codigos = [$planillaEspecifica];
    Logger::info("Procesando planilla específica: {$planillaEspecifica}");
} else {
    Logger::info("Obteniendo planillas existentes en {$target}...");
    $response = $apiClient->get('api/ferrawin/codigos-existentes');

    if (!($response['success'] ?? false)) {
        Logger::error("Error obteniendo planillas: " . ($response['error'] ?? 'desconocido'));
        exit(1);
    }

    $codigos = $response['data']['codigos'] ?? [];
    Logger::info("Planillas encontradas: " . count($codigos));
}

$totalPlanillas = count($codigos);
$totalActualizados = 0;
$totalErrores = 0;
$planillasConErrores = [];

foreach ($codigos as $idx => $codigo) {
    $progreso = sprintf("[%d/%d]", $idx + 1, $totalPlanillas);

    try {
        // 1. Obtener elementos de la BD
        $response = $apiClient->get("api/ferrawin/elementos-para-matching/{$codigo}");

        if ($verbose) {
            Logger::info("{$progreso} API response keys: " . implode(', ', array_keys($response)));
        }

        if (!($response['success'] ?? false)) {
            Logger::warning("{$progreso} Planilla {$codigo}: no encontrada en BD (success=false)");
            continue;
        }

        $elementosBD = $response['data']['elementos'] ?? [];

        if ($verbose) {
            Logger::info("{$progreso} Elementos BD count: " . count($elementosBD));
            if (!empty($elementosBD)) {
                $primerElemento = $elementosBD[0] ?? $elementosBD[array_key_first($elementosBD)] ?? [];
                Logger::info("{$progreso} Primer elemento BD keys: " . implode(', ', array_keys((array)$primerElemento)));
                Logger::info("{$progreso} Primer elemento BD: fila='" . ($primerElemento['fila'] ?? 'NULL') . "', ferrawin_id='" . ($primerElemento['ferrawin_id'] ?? 'NULL') . "', d=" . ($primerElemento['diametro'] ?? 0) . ", l=" . ($primerElemento['longitud'] ?? 0));
            }
        }

        if (empty($elementosBD)) {
            Logger::warning("{$progreso} Planilla {$codigo}: sin elementos en BD");
            continue;
        }

        // 2. Obtener elementos de FerraWin
        $elementosFerrawin = obtenerElementosFerrawin($pdo, $codigo);

        if ($verbose) {
            Logger::info("{$progreso} Elementos FerraWin count: " . count($elementosFerrawin));
            if (!empty($elementosFerrawin)) {
                Logger::info("{$progreso} Primer elemento FerraWin: fila=" . ($elementosFerrawin[0]['fila'] ?? 'N/A') . ", zelemento=" . ($elementosFerrawin[0]['zelemento'] ?? 'N/A'));
            }
        }

        if (empty($elementosFerrawin)) {
            if ($verbose) {
                Logger::warning("{$progreso} Planilla {$codigo}: no encontrada en FerraWin");
            }
            continue;
        }

        // 3. Hacer matching
        $actualizaciones = hacerMatching($elementosBD, $elementosFerrawin, $verbose);

        if (empty($actualizaciones)) {
            if ($verbose) {
                Logger::debug("{$progreso} Planilla {$codigo}: sin actualizaciones necesarias");
            }
            continue;
        }

        Logger::info("{$progreso} Planilla {$codigo}: {$actualizaciones['matched']} matches de {$actualizaciones['total_bd']} elementos");

        // 4. Enviar actualizaciones
        if (!$dryRun && !empty($actualizaciones['updates'])) {
            $result = $apiClient->post('api/ferrawin/actualizar-ferrawin-ids', [
                'actualizaciones' => $actualizaciones['updates'],
            ]);

            if ($result['success'] ?? false) {
                $totalActualizados += $result['actualizados'] ?? 0;
            } else {
                Logger::error("{$progreso} Error actualizando: " . ($result['error'] ?? 'desconocido'));
                $totalErrores++;
                $planillasConErrores[] = $codigo;
            }
        } else {
            $totalActualizados += count($actualizaciones['updates']);
        }

    } catch (Exception $e) {
        Logger::error("{$progreso} Excepción en {$codigo}: " . $e->getMessage());
        $totalErrores++;
        $planillasConErrores[] = $codigo;
    }
}

Logger::info("=== Backfill completado ===");
Logger::info("Planillas procesadas: {$totalPlanillas}");
Logger::info("Elementos actualizados: {$totalActualizados}");
Logger::info("Errores: {$totalErrores}");

if (!empty($planillasConErrores)) {
    Logger::warning("Planillas con errores: " . implode(', ', $planillasConErrores));
}

if ($dryRun) {
    Logger::info("(Simulación - ejecutar sin --dry-run para aplicar cambios)");
}

// ============================================================================
// FUNCIONES
// ============================================================================

/**
 * Obtiene elementos de FerraWin con ZELEMENTO.
 */
function obtenerElementosFerrawin(PDO $pdo, string $codigo): array
{
    $partes = explode('-', $codigo, 2);

    if (count($partes) !== 2) {
        return [];
    }

    [$zconta, $zcodigo] = $partes;

    $sql = "
        SELECT
            ob.ZCODLIN as fila,
            ob.ZELEMENTO as zelemento,
            ob.ZDIAMETRO as diametro,
            ob.ZLONGTESTD as longitud,
            ob.ZCANTIDAD as barras,
            ob.ZNUMBEND as dobles_barra,
            ob.ZPESOTESTD as peso,
            ob.ZMARCA as marca,
            ob.ZCODMODELO as figura
        FROM ORD_BAR ob
        WHERE ob.ZCONTA = :zconta AND ob.ZCODIGO = :zcodigo
        ORDER BY ob.ZCODLIN, ob.ZELEMENTO
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'zconta' => $zconta,
        'zcodigo' => $zcodigo,
    ]);

    $elementos = [];
    while ($row = $stmt->fetch()) {
        $fila = trim($row->fila ?? '');
        $zelemento = trim($row->zelemento ?? '');
        $ferrawinId = $fila . '-' . $zelemento;

        $elementos[] = [
            'ferrawin_id' => $ferrawinId,
            'fila' => $fila,
            'zelemento' => $zelemento,
            'diametro' => (int)($row->diametro ?? 0),
            'longitud' => (float)($row->longitud ?? 0),
            'barras' => (int)($row->barras ?? 0),
            'dobles_barra' => (int)($row->dobles_barra ?? 0),
            'peso' => (float)($row->peso ?? 0),
            'marca' => trim($row->marca ?? ''),
            'figura' => trim($row->figura ?? ''),
        ];
    }

    return $elementos;
}

/**
 * Hace matching entre elementos de BD y FerraWin.
 *
 * Estrategia de matching (en orden de prioridad):
 * 1. Match exacto: fila + diametro + longitud + barras + dobles_barra + peso
 * 2. Match flexible: fila + diametro + longitud + barras (sin peso que puede variar)
 * 3. Match por posición: mismo orden dentro de la fila
 */
function hacerMatching(array $elementosBD, array $elementosFerrawin, bool $verbose = false): array
{
    $updates = [];
    $matched = 0;
    $noMatched = 0;

    // Función para normalizar fila (quitar ceros a la izquierda)
    $normalizarFila = function($fila) {
        $fila = trim($fila ?? '');
        if ($fila === '') return '';
        // Quitar ceros a la izquierda pero preservar '0' si es solo ceros
        $normalizada = ltrim($fila, '0');
        return $normalizada === '' ? '0' : $normalizada;
    };

    // Indexar elementos de FerraWin por fila normalizada
    $ferrawinPorFila = [];
    foreach ($elementosFerrawin as $elem) {
        $fila = $normalizarFila($elem['fila']);
        if (!isset($ferrawinPorFila[$fila])) {
            $ferrawinPorFila[$fila] = [];
        }
        $ferrawinPorFila[$fila][] = $elem;
    }

    // Track de elementos FerraWin ya usados
    $ferrawinUsados = [];

    // Agrupar elementos BD por fila normalizada
    $bdPorFila = [];
    foreach ($elementosBD as $elem) {
        $fila = $normalizarFila($elem['fila']);
        if (!isset($bdPorFila[$fila])) {
            $bdPorFila[$fila] = [];
        }
        $bdPorFila[$fila][] = $elem;
    }

    if ($verbose) {
        Logger::info("DEBUG: BD filas: " . implode(', ', array_map(fn($f) => "'" . $f . "'(" . count($bdPorFila[$f]) . ")", array_keys($bdPorFila))));
        Logger::info("DEBUG: FW filas: " . implode(', ', array_map(fn($f) => "'" . $f . "'(" . count($ferrawinPorFila[$f]) . ")", array_keys($ferrawinPorFila))));
    }

    foreach ($bdPorFila as $fila => $elementosEnFila) {
        $ferrawinEnFila = $ferrawinPorFila[$fila] ?? [];

        if ($verbose) {
            Logger::info("DEBUG: Procesando fila '{$fila}': BD=" . count($elementosEnFila) . ", FW=" . count($ferrawinEnFila));
        }

        if (empty($ferrawinEnFila)) {
            $noMatched += count($elementosEnFila);
            if ($verbose) {
                Logger::info("DEBUG: No hay FW elementos para fila '{$fila}'");
            }
            continue;
        }

        foreach ($elementosEnFila as $elemBD) {
            // Si ya tiene ferrawin_id correcto, verificar que existe en FerraWin
            if (!empty($elemBD['ferrawin_id'])) {
                $existe = false;
                foreach ($ferrawinEnFila as $fw) {
                    if ($fw['ferrawin_id'] === $elemBD['ferrawin_id']) {
                        $existe = true;
                        break;
                    }
                }
                if ($existe) {
                    $matched++;
                    continue;
                }
            }

            // Buscar match
            $matchEncontrado = null;

            // Log primer intento de match para debug
            if ($verbose && $elemBD === $elementosEnFila[0]) {
                $fw0 = $ferrawinEnFila[0] ?? [];
                Logger::info("DEBUG: Comparando BD[0] vs FW[0]:");
                Logger::info("  BD: d=" . $elemBD['diametro'] . "(" . gettype($elemBD['diametro']) . "), l=" . $elemBD['longitud'] . "(" . gettype($elemBD['longitud']) . "), b=" . $elemBD['barras'] . ", db=" . $elemBD['dobles_barra'] . ", p=" . $elemBD['peso']);
                Logger::info("  FW: d=" . $fw0['diametro'] . "(" . gettype($fw0['diametro']) . "), l=" . $fw0['longitud'] . "(" . gettype($fw0['longitud']) . "), b=" . $fw0['barras'] . ", db=" . $fw0['dobles_barra'] . ", p=" . $fw0['peso']);
            }

            // 1. Match exacto
            foreach ($ferrawinEnFila as $idx => $fw) {
                $key = $fila . '-' . $fw['zelemento'];
                if (isset($ferrawinUsados[$key])) continue;

                if ($elemBD['diametro'] == $fw['diametro'] &&
                    abs($elemBD['longitud'] - $fw['longitud']) < 1 &&
                    $elemBD['barras'] == $fw['barras'] &&
                    $elemBD['dobles_barra'] == $fw['dobles_barra'] &&
                    abs($elemBD['peso'] - $fw['peso']) < 0.01) {
                    $matchEncontrado = $fw;
                    $ferrawinUsados[$key] = true;
                    break;
                }
            }

            // 2. Match flexible (sin peso)
            if (!$matchEncontrado) {
                foreach ($ferrawinEnFila as $idx => $fw) {
                    $key = $fila . '-' . $fw['zelemento'];
                    if (isset($ferrawinUsados[$key])) continue;

                    if ($elemBD['diametro'] == $fw['diametro'] &&
                        abs($elemBD['longitud'] - $fw['longitud']) < 1 &&
                        $elemBD['barras'] == $fw['barras'] &&
                        $elemBD['dobles_barra'] == $fw['dobles_barra']) {
                        $matchEncontrado = $fw;
                        $ferrawinUsados[$key] = true;
                        break;
                    }
                }
            }

            // 3. Match por posición (último recurso)
            if (!$matchEncontrado) {
                foreach ($ferrawinEnFila as $idx => $fw) {
                    $key = $fila . '-' . $fw['zelemento'];
                    if (isset($ferrawinUsados[$key])) continue;

                    // Solo si coincide al menos diámetro y longitud
                    if ($elemBD['diametro'] == $fw['diametro'] &&
                        abs($elemBD['longitud'] - $fw['longitud']) < 10) {
                        $matchEncontrado = $fw;
                        $ferrawinUsados[$key] = true;
                        break;
                    }
                }
            }

            if ($matchEncontrado) {
                $updates[] = [
                    'elemento_id' => $elemBD['id'],
                    'ferrawin_id' => $matchEncontrado['ferrawin_id'],
                ];
                $matched++;
            } else {
                $noMatched++;
                if ($verbose) {
                    Logger::debug("  Sin match para elemento BD #{$elemBD['id']} fila={$fila} d={$elemBD['diametro']} l={$elemBD['longitud']}");
                }
            }
        }
    }

    return [
        'updates' => $updates,
        'matched' => $matched,
        'no_matched' => $noMatched,
        'total_bd' => count($elementosBD),
        'total_ferrawin' => count($elementosFerrawin),
    ];
}
