<?php
/**
 * Sync Listener - Cliente WebSocket para recibir comandos de sincronización via Pusher.
 *
 * Este script se ejecuta como un daemon permanente en Windows y escucha
 * comandos enviados desde el servidor de producción via Pusher Channels.
 *
 * Uso:
 *   php sync-listener.php              # Iniciar listener
 *   php sync-listener.php --test       # Modo de prueba (no ejecuta sync, solo muestra comandos)
 *
 * Requisitos:
 *   - composer require pusher/pusher-php-server ratchet/pawl
 *   - Credenciales Pusher en .env
 */

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Client\Connector;
use React\EventLoop\Factory as LoopFactory;
use Pusher\Pusher;

// Cargar configuración desde .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Directorio base
define('BASE_DIR', __DIR__);
define('LOGS_DIR', BASE_DIR . '/logs');
define('PAUSE_FILE', BASE_DIR . '/sync.pause');
define('LISTENER_PID_FILE', BASE_DIR . '/listener.pid');
define('STATUS_FILE', BASE_DIR . '/listener.status');
define('SYNC_LOGFILE_TRACKER', BASE_DIR . '/sync.logfile');
define('AUTOSYNC_FILE', BASE_DIR . '/sync.autosync');

// Configuración Pusher desde .env
$pusherConfig = [
    'app_id' => $_ENV['PUSHER_APP_ID'] ?? '',
    'key' => $_ENV['PUSHER_APP_KEY'] ?? '',
    'secret' => $_ENV['PUSHER_APP_SECRET'] ?? '',
    'cluster' => $_ENV['PUSHER_APP_CLUSTER'] ?? 'eu',
];

// Configuración API para enviar estados a producción
// Usa PRODUCTION_URL y PRODUCTION_TOKEN ya existentes, o variables específicas si se definen
$apiConfig = [
    'base_url' => $_ENV['API_BASE_URL'] ?? $_ENV['PRODUCTION_URL'] ?? 'https://app.hierrospacoreyes.es',
    'token' => $_ENV['API_STATUS_TOKEN'] ?? $_ENV['PRODUCTION_TOKEN'] ?? '',
];

// Validar configuración
if (empty($pusherConfig['key']) || empty($pusherConfig['secret'])) {
    echo "[ERROR] Faltan credenciales Pusher en .env\n";
    echo "Asegúrate de configurar: PUSHER_APP_ID, PUSHER_APP_KEY, PUSHER_APP_SECRET, PUSHER_APP_CLUSTER\n";
    exit(1);
}

// Modo de prueba
$testMode = in_array('--test', $argv);

// Estado de auto-sync (cargado desde archivo, persiste entre reinicios del listener)
$autoSyncState = [
    'enabled'  => true,
    'interval' => 30,      // minutos
    'target'   => 'production',
    'lastRun'  => 0,       // timestamp UNIX de la última ejecución auto
];
if (file_exists(AUTOSYNC_FILE)) {
    $saved = json_decode(file_get_contents(AUTOSYNC_FILE), true);
    if (is_array($saved)) {
        $autoSyncState = array_merge($autoSyncState, $saved);
    }
}

logMessage("Auto-sync: " . ($autoSyncState['enabled'] ? "activado cada {$autoSyncState['interval']} min" : "desactivado"));

// Detectar ruta de PHP
function getPhpPath(): string
{
    $paths = [
        'C:\\xampp\\php\\php.exe',
        'C:\\php\\php.exe',
        BASE_DIR . '\\php\\php.exe',
        PHP_BINARY, // El PHP que está ejecutando este script
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    // Fallback: usar el PHP actual
    return PHP_BINARY;
}

/**
 * Logger simple para el listener
 */
function logMessage(string $message, string $level = 'INFO'): void
{
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$level}] {$message}\n";

    echo $logLine;

    // También escribir a archivo de log
    $logFile = LOGS_DIR . '/listener-' . date('Y-m-d') . '.log';
    if (!is_dir(LOGS_DIR)) {
        mkdir(LOGS_DIR, 0755, true);
    }
    file_put_contents($logFile, $logLine, FILE_APPEND);
}

/**
 * Guarda el estado actual del listener
 */
function saveStatus(array $status): void
{
    $status['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents(STATUS_FILE, json_encode($status, JSON_PRETTY_PRINT));
}

/**
 * Crea instancia de Pusher para enviar eventos
 */
function getPusher(): Pusher
{
    global $pusherConfig;
    return new Pusher(
        $pusherConfig['key'],
        $pusherConfig['secret'],
        $pusherConfig['app_id'],
        ['cluster' => $pusherConfig['cluster'], 'useTLS' => true]
    );
}

/**
 * Envía estado de sincronización a producción via API HTTP
 */
function enviarEstado(string $status, ?string $progress = null, ?string $message = null, ?string $year = null, ?string $lastPlanilla = null, ?string $target = null, ?string $lastPlanillaInfo = null, bool $esActualizacion = false, array $detectionStats = []): void
{
    global $apiConfig, $syncTarget;

    try {
        $data = [
            'status'              => $status,
            'progress'            => $progress,
            'message'             => $message,
            'year'                => $year,
            'target'              => $target ?? $syncTarget ?? 'production',
            'last_planilla'       => $lastPlanilla,
            'last_planilla_info'  => $lastPlanillaInfo,
            'es_actualizacion'    => $esActualizacion,
            'detection_stats'     => !empty($detectionStats) ? $detectionStats : null,
        ];

        // Enviar via API HTTP
        $url = rtrim($apiConfig['base_url'], '/') . '/api/ferrawin/sync-status';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiConfig['token'],
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // curl_close() no es necesario en PHP 8+

        if ($httpCode >= 200 && $httpCode < 300) {
            logMessage("Estado enviado via API: {$status}" . ($progress ? " ({$progress})" : ""));
        } else {
            logMessage("Error API ({$httpCode}): {$response} - {$error}", 'WARNING');
        }
    } catch (\Exception $e) {
        logMessage("Error enviando estado: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Ejecuta la sincronización con los parámetros especificados
 */
function sincronizaActiva(): bool
{
    $pidFile = BASE_DIR . '/sync.pid';
    if (!file_exists($pidFile)) {
        return false;
    }
    $pid = (int) trim(file_get_contents($pidFile));
    if ($pid <= 0) {
        @unlink($pidFile);
        return false;
    }
    exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
    foreach ($output as $line) {
        if (strpos($line, (string) $pid) !== false) {
            return true;
        }
    }
    // PID huérfano — limpiar
    @unlink($pidFile);
    return false;
}

/**
 * Lanza sync-optimizado.php en background usando VBScript (sin ventana).
 * Centraliza la ejecución para no duplicar el código VBScript en múltiples lugares.
 */
function lanzarProceso(string $args, array $params): void
{
    $phpPath   = getPhpPath();
    $scriptPath = BASE_DIR . '\sync-optimizado.php';
    $vbsFile    = BASE_DIR . '\run-sync-from-listener.vbs';

    $vbs  = 'Set WshShell = CreateObject("WScript.Shell")' . "\r\n";
    $vbs .= 'WshShell.Run """' . $phpPath . '"" ""' . $scriptPath . '"" ' . $args . '", 0, False' . "\r\n";
    file_put_contents($vbsFile, $vbs);

    exec("wscript //nologo \"$vbsFile\"", $output, $returnCode);

    if ($returnCode === 0) {
        logMessage("Proceso iniciado correctamente: $args");
        saveStatus(['state' => 'running', 'command' => 'start', 'params' => $params]);
    } else {
        logMessage("Error al iniciar proceso (código: {$returnCode})", 'ERROR');
        enviarEstado('error', null, "Error al iniciar (código: {$returnCode})");
    }
}

/**
 * Prepara y lanza la sincronización según el modo recibido.
 * Acepta 'modo' (nuevo) o 'año' (legado) en $params.
 */
function ejecutarSync(array $params, bool $testMode): void
{
    global $syncYear, $syncTarget;

    // Soporte tanto para 'modo' (nuevo) como 'año' (legado por compatibilidad)
    $modo             = $params['modo'] ?? $params['año'] ?? 'incremental';
    $target           = $params['target'] ?? 'production';
    $syncTarget       = $target;
    $desdeCodigo      = $params['desde_codigo'] ?? null;
    $codigoEspecifico = $params['codigo_especifico'] ?? null;
    $añoHistorico     = $params['anio'] ?? null;

    // Verificar si ya hay una sincronización en curso
    if (!$testMode && sincronizaActiva()) {
        logMessage('Comando start ignorado: ya hay una sincronización en curso', 'WARNING');
        enviarEstado('busy', null, 'Ya hay una sincronización en curso. Detenla antes de iniciar una nueva.');
        return;
    }

    // === Planilla específica ===
    if ($modo === 'especifica' && $codigoEspecifico) {
        $syncYear = substr($codigoEspecifico, 0, 4);
        $args     = "--codigo={$codigoEspecifico} --target={$target}";
        logMessage("Sincronizando planilla específica: {$args}");
        if ($testMode) { logMessage("[MODO TEST] php sync-optimizado.php {$args}"); return; }
        if (file_exists(PAUSE_FILE)) unlink(PAUSE_FILE);
        file_put_contents(SYNC_LOGFILE_TRACKER, LOGS_DIR . '/sync-' . date('Y-m-d') . '.log');
        enviarEstado('running', '1/1', "Sincronizando {$codigoEspecifico}", $syncYear, $codigoEspecifico);
        lanzarProceso($args, $params);
        return;
    }

    // === Determinar args según modo ===
    switch ($modo) {
        case 'rebuild':
        case 'todos':   // legado
            $args     = "--rebuild --target={$target}";
            $mensaje  = 'Reconstrucción total iniciada';
            $syncYear = 'rebuild';
            break;

        case 'historico':
            if (!$añoHistorico) { logMessage('Error: modo historico sin anio', 'ERROR'); return; }
            $args     = "--anio={$añoHistorico} --target={$target}";
            $mensaje  = "Histórico {$añoHistorico} iniciado";
            $syncYear = $añoHistorico;
            if ($desdeCodigo) { $args .= " --desde-codigo={$desdeCodigo}"; $mensaje .= " desde {$desdeCodigo}"; }
            break;

        default:
            // incremental, nuevas (legado), modificadas (legado) — todos detectan nuevas + modificadas
            $args     = "--target={$target}";
            $mensaje  = 'Sincronización incremental iniciada (nuevas + modificadas)';
            $syncYear = 'incremental';
            if ($desdeCodigo) { $args .= " --desde-codigo={$desdeCodigo}"; $mensaje .= " desde {$desdeCodigo}"; }
            break;
    }

    logMessage("Preparando: {$args}");

    if ($testMode) {
        logMessage("[MODO TEST] php sync-optimizado.php {$args}");
        return;
    }

    if (file_exists(PAUSE_FILE)) unlink(PAUSE_FILE);
    file_put_contents(SYNC_LOGFILE_TRACKER, LOGS_DIR . '/sync-' . date('Y-m-d') . '.log');
    enviarEstado('running', '0/?', $mensaje, $syncYear);
    lanzarProceso($args, $params);
}
function matarSync(bool $testMode): void
{
    global $syncYear, $syncTarget, $lastProgress;

    logMessage("Recibido comando de forzar detención (kill)");

    if ($testMode) {
        logMessage("[MODO TEST] Se mataría el proceso de sync");
        return;
    }

    $pidFile = BASE_DIR . '/sync.pid';

    if (!file_exists($pidFile)) {
        logMessage("No hay sincronización en curso (no existe sync.pid)");
        enviarEstado('idle', null, 'No había sincronización en curso');
        return;
    }

    $pid = (int) trim(file_get_contents($pidFile));

    if ($pid <= 0) {
        logMessage("PID inválido en sync.pid: {$pid}", 'WARNING');
        @unlink($pidFile);
        return;
    }

    // Matar el proceso
    exec("taskkill /PID {$pid} /F 2>&1", $output, $returnCode);

    if ($returnCode === 0) {
        logMessage("Proceso {$pid} matado correctamente");
    } else {
        logMessage("No se pudo matar proceso {$pid}: " . implode(' ', $output), 'WARNING');
    }

    // Limpiar archivos de control
    @unlink($pidFile);
    if (file_exists(PAUSE_FILE)) {
        @unlink(PAUSE_FILE);
    }

    enviarEstado('stopped', $lastProgress, 'Sincronización detenida forzosamente', $syncYear);

    $lastProgress = null;
    $syncYear = null;
    $syncTarget = null;

    saveStatus([
        'state' => 'idle',
        'command' => 'kill',
    ]);

    logMessage("Archivos de control limpiados");
}

/**
 * Pausa la sincronización en ejecución
 */
function pausarSync(bool $testMode): void
{
    logMessage("Recibido comando de pausa");

    if ($testMode) {
        logMessage("[MODO TEST] Se crearía archivo de pausa");
        return;
    }

    $pidFile = BASE_DIR . '/sync.pid';

    if (!file_exists($pidFile)) {
        logMessage("No hay sincronización en curso (no existe sync.pid), ignorando pausa");
        enviarEstado('idle', null, 'No había sincronización en curso');
        return;
    }

    $pid = (int) trim(file_get_contents($pidFile));

    if ($pid <= 0) {
        logMessage("PID inválido en sync.pid: {$pid}, ignorando pausa", 'WARNING');
        @unlink($pidFile);
        enviarEstado('idle', null, 'No había sincronización en curso');
        return;
    }

    // Verificar que el proceso sigue vivo
    exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
    $isAlive = false;
    foreach ($output as $line) {
        if (strpos($line, (string) $pid) !== false) {
            $isAlive = true;
            break;
        }
    }

    if (!$isAlive) {
        logMessage("Proceso sync (PID {$pid}) ya no está activo, ignorando pausa");
        @unlink($pidFile);
        enviarEstado('idle', null, 'Sincronización ya finalizada');
        return;
    }

    file_put_contents(PAUSE_FILE, date('Y-m-d H:i:s'));
    logMessage("Archivo de pausa creado para PID {$pid}");

    saveStatus([
        'state' => 'pausing',
        'command' => 'pause',
    ]);
}

/**
 * Procesa un comando recibido
 */
function procesarComando(array $data, bool $testMode): void
{
    $command = $data['command'] ?? '';
    $params = $data['params'] ?? [];
    $requestId = $data['requestId'] ?? '';

    logMessage("Comando recibido: {$command} (requestId: {$requestId})");

    switch ($command) {
        case 'start':
            ejecutarSync($params, $testMode);
            break;

        case 'pause':
            pausarSync($testMode);
            break;

        case 'kill':
            matarSync($testMode);
            break;

        case 'status':
            logMessage("Comando status recibido");
            break;

        case 'configure-auto-sync':
            global $autoSyncState;
            if (isset($params['enabled']))  $autoSyncState['enabled']  = (bool) $params['enabled'];
            if (isset($params['interval'])) $autoSyncState['interval'] = max(5, (int) $params['interval']);
            if (isset($params['target']))   $autoSyncState['target']   = $params['target'];
            file_put_contents(AUTOSYNC_FILE, json_encode($autoSyncState, JSON_PRETTY_PRINT));
            $autoSyncState['lastRun'] = 0; // Resetear para que no salte inmediatamente
            logMessage('Auto-sync reconfigurado: ' . ($autoSyncState['enabled']
                ? "activado cada {$autoSyncState['interval']} min → {$autoSyncState['target']}"
                : 'desactivado'));
            break;

        default:
            logMessage("Comando desconocido: {$command}", 'WARNING');
    }
}

/**
 * Genera la firma para autenticación de canal privado
 */
function generateSocketSignature(string $socketId, string $channel, string $key, string $secret): string
{
    $stringToSign = "{$socketId}:{$channel}";
    $signature = hash_hmac('sha256', $stringToSign, $secret);
    return "{$key}:{$signature}";
}

// Verificar que no haya otro listener corriendo
if (file_exists(LISTENER_PID_FILE)) {
    $existingPid = (int) trim(file_get_contents(LISTENER_PID_FILE));
    if ($existingPid > 0) {
        exec("tasklist /FI \"PID eq {$existingPid}\" 2>NUL", $output);
        $isRunning = false;
        foreach ($output as $line) {
            if (strpos($line, (string)$existingPid) !== false) {
                $isRunning = true;
                break;
            }
        }
        if ($isRunning) {
            echo "[ERROR] Ya hay un listener corriendo (PID: {$existingPid})\n";
            exit(1);
        }
    }
}

// Guardar PID del listener
file_put_contents(LISTENER_PID_FILE, getmypid());

// Limpiar PID al terminar
register_shutdown_function(function() {
    if (file_exists(LISTENER_PID_FILE)) {
        unlink(LISTENER_PID_FILE);
    }
});

// Configuración de WebSocket
$wsUrl = "wss://ws-{$pusherConfig['cluster']}.pusher.com:443/app/{$pusherConfig['key']}?protocol=7&client=php&version=1.0";

logMessage("=== Sync Listener Iniciado ===");
logMessage("Cluster: {$pusherConfig['cluster']}");
logMessage("Modo: " . ($testMode ? "PRUEBA" : "PRODUCCIÓN"));
logMessage("Conectando a Pusher WebSocket...");

saveStatus([
    'state' => 'connecting',
    'pid' => getmypid(),
    'test_mode' => $testMode,
]);

// Crear event loop
$loop = LoopFactory::create();
$connector = new Connector($loop);

// Variables de estado
$socketId = null;
$reconnectDelay = 1;
$maxReconnectDelay = 60;

/**
 * Función para conectar a Pusher
 */
$connect = function() use ($connector, $wsUrl, $pusherConfig, $testMode, &$loop, &$connect, &$reconnectDelay, $maxReconnectDelay) {
    $connector($wsUrl)->then(
        function($conn) use ($pusherConfig, $testMode, &$loop, &$connect, &$reconnectDelay) {
            global $socketId;

            logMessage("Conectado a Pusher WebSocket");
            $reconnectDelay = 1; // Reset delay on successful connection

            $conn->on('message', function($msg) use ($conn, $pusherConfig, $testMode) {
                global $socketId;

                // Convertir mensaje a string si es un objeto Message
                $msgString = is_string($msg) ? $msg : (string)$msg;
                $data = json_decode($msgString, true);
                $event = $data['event'] ?? '';

                switch ($event) {
                    case 'pusher:connection_established':
                        $eventData = json_decode($data['data'], true);
                        $socketId = $eventData['socket_id'];
                        logMessage("Conexión establecida (socket_id: {$socketId})");

                        // Suscribirse al canal privado (sync control)
                        $channel = 'private-sync-control';
                        $auth = generateSocketSignature(
                            $socketId,
                            $channel,
                            $pusherConfig['key'],
                            $pusherConfig['secret']
                        );

                        $subscribeMsg = json_encode([
                            'event' => 'pusher:subscribe',
                            'data' => [
                                'auth' => $auth,
                                'channel' => $channel,
                            ],
                        ]);

                        $conn->send($subscribeMsg);
                        logMessage("Suscribiéndose a canal: {$channel}");

                        // Suscribirse al canal público (Wake-on-LAN)
                        $wolChannel = 'ferrawin-sync';
                        $subscribeWolMsg = json_encode([
                            'event' => 'pusher:subscribe',
                            'data' => [
                                'channel' => $wolChannel,
                            ],
                        ]);

                        $conn->send($subscribeWolMsg);
                        logMessage("Suscribiéndose a canal: {$wolChannel}");
                        break;

                    case 'pusher_internal:subscription_succeeded':
                        $channel = $data['channel'] ?? '';
                        logMessage("Suscripción exitosa a: {$channel}");
                        saveStatus([
                            'state' => 'listening',
                            'channel' => $channel,
                            'socket_id' => $socketId,
                            'pid' => getmypid(),
                            'test_mode' => $testMode,
                        ]);
                        break;

                    case 'pusher:error':
                        $errorData = json_decode($data['data'] ?? '{}', true);
                        logMessage("Error de Pusher: " . ($errorData['message'] ?? 'Unknown'), 'ERROR');
                        break;

                    case 'sync.command':
                        // Evento personalizado de comando de sincronización
                        $commandData = json_decode($data['data'], true);
                        procesarComando($commandData, $testMode);
                        break;


                    case 'pusher:ping':
                        $conn->send(json_encode(['event' => 'pusher:pong', 'data' => '{}']));
                        break;

                    default:
                        if (strpos($event, 'pusher:') !== 0) {
                            logMessage("Evento recibido: {$event}");
                        }
                }
            });

            $conn->on('close', function($code = null, $reason = null) use (&$loop, &$connect, &$reconnectDelay) {
                logMessage("Conexión cerrada (código: {$code}, razón: {$reason})", 'WARNING');
                saveStatus(['state' => 'disconnected']);

                // Reconectar después de un delay
                logMessage("Reconectando en {$reconnectDelay} segundos...");
                $loop->addTimer($reconnectDelay, $connect);
                $reconnectDelay = min($reconnectDelay * 2, 60);
            });

            $conn->on('error', function($error) {
                logMessage("Error de conexión: " . $error->getMessage(), 'ERROR');
            });
        },
        function($error) use (&$loop, &$connect, &$reconnectDelay, $maxReconnectDelay) {
            logMessage("Error conectando: " . $error->getMessage(), 'ERROR');
            saveStatus(['state' => 'error', 'message' => $error->getMessage()]);

            // Reintentar conexión
            logMessage("Reintentando en {$reconnectDelay} segundos...");
            $loop->addTimer($reconnectDelay, $connect);
            $reconnectDelay = min($reconnectDelay * 2, $maxReconnectDelay);
        }
    );
};

// Iniciar conexión
$connect();

// Variables globales para tracking
$syncYear = null;
$syncTarget = null;
$lastProgress = null;
$lastSyncCheck = 0;

// Monitor de sincronización cada 10 segundos
$loop->addPeriodicTimer(10, function() use ($testMode) {
    global $syncYear, $syncTarget, $lastProgress, $lastSyncCheck;

    $pidFile = BASE_DIR . '/sync.pid';

    // Resolver el log activo: usar el path guardado al arrancar la sync para no
    // perder el tracking cuando el log rota a medianoche.
    if (file_exists(SYNC_LOGFILE_TRACKER)) {
        $logFile = trim(file_get_contents(SYNC_LOGFILE_TRACKER));
        // Si el log guardado ya no existe pero hay uno del día actual, usar el actual
        if (!file_exists($logFile)) {
            $logFile = LOGS_DIR . '/sync-' . date('Y-m-d') . '.log';
        }
    } else {
        $logFile = LOGS_DIR . '/sync-' . date('Y-m-d') . '.log';
    }

    // Verificar si hay una sincronización en curso
    if (file_exists($pidFile)) {
        $pid = (int) trim(file_get_contents($pidFile));

        // Verificar si el proceso existe
        exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
        $isRunning = false;
        foreach ($output as $line) {
            if (strpos($line, (string)$pid) !== false) {
                $isRunning = true;
                break;
            }
        }

        if ($isRunning && file_exists($logFile)) {
            // Leer últimas líneas del log para obtener progreso
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_slice($lines, -50); // Últimas 50 líneas

            $progress         = null;
            $lastPlanilla     = null;
            $lastPlanillaInfo = null;
            $esActualizacion  = false;

            foreach (array_reverse($lines) as $line) {
                if (preg_match('/\[(\d+)\/(\d+)\]/', $line, $matches)) {
                    $progress = "{$matches[1]}/{$matches[2]}";
                }
                if (!$lastPlanilla && preg_match(
                    '/Preparando (\d{4}-\d+)(\s+\[ACTUALIZACIÓN\])?(?:\s+\|\s+(.+?))?\s+\(/',
                    $line,
                    $planillaMatch
                )) {
                    $lastPlanilla     = $planillaMatch[1];
                    $esActualizacion  = !empty($planillaMatch[2]);
                    $lastPlanillaInfo = isset($planillaMatch[3]) ? trim($planillaMatch[3]) : null;
                }
                if ($progress && $lastPlanilla) {
                    break;
                }
            }

            // Solo enviar si el progreso cambió
            if ($progress && $progress !== $lastProgress) {
                $lastProgress = $progress;
                enviarEstado('running', $progress, "Procesando {$lastPlanilla}", $syncYear, $lastPlanilla, null, $lastPlanillaInfo, $esActualizacion);
            }
        } elseif (!$isRunning) {
            // Proceso terminó - leer resumen detallado del log
            $mensaje = 'Sincronización finalizada';
            $estadoFinal = 'completed';

            $detectionStats = [];

            if (file_exists($logFile)) {
                $lines     = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $lastLines = array_slice($lines, -30);

                // Buscar RESUMEN con stats de procesamiento
                foreach ($lastLines as $line) {
                    if (strpos($line, 'RESUMEN:') !== false) {
                        if (preg_match('/RESUMEN: (.+) ===/', $line, $m)) {
                            $mensaje = $m[1];
                        }
                        break;
                    }
                }

                // Parsear SYNC_STATS (nuevas + modificadas detectadas)
                foreach ($lines as $line) {
                    if (preg_match('/SYNC_STATS: nuevas=(\d+) modificadas=(\d+)/', $line, $m)) {
                        $detectionStats = ['nuevas' => (int)$m[1], 'modificadas' => (int)$m[2]];
                        break;
                    }
                }

                // Detectar si fue pausa o detención remota
                $lastLine = end($lastLines);
                if (strpos($lastLine, 'PAUSADO') !== false || strpos($lastLine, 'pausa') !== false) {
                    $estadoFinal = 'paused';
                    $mensaje     = 'Sincronización pausada';
                } elseif (strpos($lastLine, 'DETENIDO remotamente') !== false) {
                    $estadoFinal = 'paused';
                    $mensaje     = 'Sincronización detenida remotamente';
                }

                // Detectar batches fallidos definitivos
                $noSincronizadas = array_filter($lastLines, fn($l) => strpos($l, 'NO SINCRONIZADA:') !== false);
                if (!empty($noSincronizadas)) {
                    $mensaje .= ' | ' . count($noSincronizadas) . ' batch(es) fallidos';
                }
            }

            enviarEstado($estadoFinal, $lastProgress, $mensaje, $syncYear, null, null, null, false, $detectionStats);
            logMessage("Sync finalizado: {$mensaje}");

            // Limpiar archivos de control al terminar
            if (file_exists($pidFile)) {
                unlink($pidFile);
                logMessage("PID file limpiado (proceso ya no existe)");
            }
            if (file_exists(SYNC_LOGFILE_TRACKER)) {
                unlink(SYNC_LOGFILE_TRACKER);
            }

            $lastProgress = null;
            $syncYear = null;
            $syncTarget = null;
        }
    } else {
        // No hay sincronización en curso
        if ($lastProgress !== null) {
            // Acabó de terminar
            enviarEstado('idle', null, 'Sin sincronización activa');
            $lastProgress = null;
            $syncYear = null;
            $syncTarget = null;
        }
    }
});

// Heartbeat cada 30 segundos para mantener el estado actualizado
$loop->addPeriodicTimer(30, function() use ($testMode) {
    if (file_exists(STATUS_FILE)) {
        $status = json_decode(file_get_contents(STATUS_FILE), true);
        if (($status['state'] ?? '') === 'listening') {
            $status['heartbeat'] = date('Y-m-d H:i:s');
            file_put_contents(STATUS_FILE, json_encode($status, JSON_PRETTY_PRINT));
        }
    }
});

// Auto-sync: comprueba cada 60 s si corresponde lanzar una sincronización automática
$loop->addPeriodicTimer(60, function() use ($testMode, &$autoSyncState) {
    if (!$autoSyncState['enabled']) {
        return;
    }
    if (sincronizaActiva()) {
        return; // Ya hay una sync en curso, no lanzar otra
    }

    $elapsed  = time() - ($autoSyncState['lastRun'] ?? 0);
    $interval = ($autoSyncState['interval'] ?? 30) * 60;

    if ($elapsed >= $interval) {
        $autoSyncState['lastRun'] = time();
        file_put_contents(AUTOSYNC_FILE, json_encode($autoSyncState, JSON_PRETTY_PRINT));
        logMessage("Auto-sync: lanzando sincronización incremental (han pasado " . round($elapsed / 60) . " min)");
        ejecutarSync(['modo' => 'incremental', 'target' => $autoSyncState['target'] ?? 'production'], $testMode);
    }
});

// Ejecutar el loop
$loop->run();
