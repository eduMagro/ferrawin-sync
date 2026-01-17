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
function enviarEstado(string $status, ?string $progress = null, ?string $message = null, ?string $year = null, ?string $lastPlanilla = null): void
{
    global $apiConfig;

    try {
        $data = [
            'status' => $status,
            'progress' => $progress,
            'message' => $message,
            'year' => $year,
            'target' => 'production',
            'last_planilla' => $lastPlanilla,
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
        curl_close($ch);

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
function ejecutarSync(array $params, bool $testMode): void
{
    global $syncYear;

    $año = $params['año'] ?? null;
    $target = $params['target'] ?? 'local';
    $desdeCodigo = $params['desde_codigo'] ?? null;

    if (!$año) {
        logMessage("Error: Falta parámetro 'año' en el comando", 'ERROR');
        enviarEstado('error', null, 'Falta parámetro año');
        return;
    }

    $syncYear = $año;

    // Construir argumentos
    if ($año === 'todos') {
        $args = "--todos --target={$target}";
        $mensaje = "Sincronización completa iniciada";
    } elseif ($año === 'nuevas') {
        $args = "--nuevas --target={$target}";
        $mensaje = "Sincronización de nuevas iniciada";
    } else {
        $args = "--anio={$año} --target={$target}";
        $mensaje = "Sincronización {$año} iniciada";
        if ($desdeCodigo) {
            $args .= " --desde-codigo={$desdeCodigo}";
            $mensaje .= " desde {$desdeCodigo}";
        }
    }

    logMessage("Preparando sincronización: {$args}");

    if ($testMode) {
        logMessage("[MODO TEST] Se ejecutaría: php sync-optimizado.php {$args}");
        enviarEstado('test', null, "Modo test: {$args}");
        return;
    }

    // Limpiar archivo de pausa si existe
    if (file_exists(PAUSE_FILE)) {
        unlink(PAUSE_FILE);
    }

    // Enviar estado "iniciando"
    enviarEstado('running', '0/?', $mensaje, $año);

    // Ejecutar en background usando VBScript (sin ventana visible)
    $phpPath = 'C:\\xampp\\php\\php.exe';
    $scriptPath = BASE_DIR . '\\sync-optimizado.php';

    $vbsFile = BASE_DIR . '\\run-sync-from-listener.vbs';
    $vbsContent = 'Set WshShell = CreateObject("WScript.Shell")' . "\r\n";
    $vbsContent .= 'WshShell.Run """' . $phpPath . '"" ""' . $scriptPath . '"" ' . $args . '", 0, False' . "\r\n";
    file_put_contents($vbsFile, $vbsContent);

    exec("wscript //nologo \"{$vbsFile}\"", $output, $returnCode);

    if ($returnCode === 0) {
        logMessage("Sincronización iniciada correctamente");
        saveStatus([
            'state' => 'running',
            'command' => 'start',
            'params' => $params,
        ]);
    } else {
        logMessage("Error al iniciar sincronización (código: {$returnCode})", 'ERROR');
        enviarEstado('error', null, "Error al iniciar (código: {$returnCode})");
    }
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

    file_put_contents(PAUSE_FILE, date('Y-m-d H:i:s'));
    logMessage("Archivo de pausa creado");

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

        case 'status':
            logMessage("Comando status recibido - respondiendo con estado actual");
            // TODO: Implementar respuesta de estado via Pusher
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

                        // Suscribirse al canal privado
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

// Variable global para tracking
$syncYear = null;
$lastProgress = null;
$lastSyncCheck = 0;

// Monitor de sincronización cada 10 segundos
$loop->addPeriodicTimer(10, function() use ($testMode) {
    global $syncYear, $lastProgress, $lastSyncCheck;

    $pidFile = BASE_DIR . '/sync.pid';
    $logFile = LOGS_DIR . '/sync-' . date('Y-m-d') . '.log';

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

            $progress = null;
            $lastPlanilla = null;

            foreach (array_reverse($lines) as $line) {
                if (preg_match('/\[(\d+)\/(\d+)\]/', $line, $matches)) {
                    $progress = "{$matches[1]}/{$matches[2]}";
                }
                if (!$lastPlanilla && preg_match('/Preparando (\d{4}-\d+)/', $line, $planillaMatch)) {
                    $lastPlanilla = $planillaMatch[1];
                }
                if ($progress && $lastPlanilla) {
                    break;
                }
            }

            // Solo enviar si el progreso cambió
            if ($progress && $progress !== $lastProgress) {
                $lastProgress = $progress;
                enviarEstado('running', $progress, "Procesando {$lastPlanilla}", $syncYear, $lastPlanilla);
            }
        } elseif (!$isRunning) {
            // Proceso terminó - verificar si fue exitoso o con error
            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $lastLine = end($lines);

                if (strpos($lastLine, 'completada') !== false || strpos($lastLine, 'finalizada') !== false) {
                    enviarEstado('completed', $lastProgress, 'Sincronización completada', $syncYear);
                } elseif (strpos($lastLine, 'pausada') !== false || strpos($lastLine, 'pausa') !== false) {
                    enviarEstado('paused', $lastProgress, 'Sincronización pausada', $syncYear);
                } else {
                    enviarEstado('completed', $lastProgress, 'Sincronización finalizada', $syncYear);
                }
            }
            $lastProgress = null;
            $syncYear = null;
        }
    } else {
        // No hay sincronización en curso
        if ($lastProgress !== null) {
            // Acabó de terminar
            enviarEstado('idle', null, 'Sin sincronización activa');
            $lastProgress = null;
            $syncYear = null;
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

// Ejecutar el loop
$loop->run();
