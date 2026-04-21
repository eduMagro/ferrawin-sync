<?php

namespace FerrawinSync;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Cliente HTTP para enviar datos a producción.
 */
class ApiClient
{
    private Client $client;
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = Config::production('url');
        $this->token = Config::production('token');

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 300, // 5 minutos para batches grandes
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->token,
            ],
        ]);
    }

    /**
     * Envía múltiples planillas a producción en un solo request.
     */
    public function enviarPlanillas(array $planillas, array $metadata = []): array
    {
        $compress = Config::get('sync.compress', true);

        try {
            $data = [
                'planillas' => $planillas,
                'metadata' => $metadata,
            ];

            $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

            $options = ['body' => $payload];

            // Comprimir si está habilitado
            if ($compress && function_exists('gzencode')) {
                $options['body'] = gzencode($payload, 9);
                $options['headers'] = [
                    'Content-Encoding' => 'gzip',
                    'Authorization' => 'Bearer ' . $this->token,
                ];
            }

            $response = $this->client->post('api/ferrawin/sync', $options);

            $result = json_decode($response->getBody()->getContents(), true);

            $totalElementos = array_sum(array_map(fn($p) => count($p['elementos'] ?? []), $planillas));

            Logger::info("Planillas enviadas", [
                'cantidad' => count($planillas),
                'elementos_total' => $totalElementos,
                'comprimido' => $compress,
            ]);

            // Loguear respuesta del servidor para diagnóstico
            if (isset($result['data'])) {
                Logger::info("Respuesta servidor", [
                    'creadas' => $result['data']['planillas_creadas'] ?? 0,
                    'actualizadas' => $result['data']['planillas_actualizadas'] ?? 0,
                    'omitidas' => $result['data']['planillas_omitidas'] ?? 0,
                    'elementos' => $result['data']['elementos_creados'] ?? 0,
                ]);
            }

            return [
                'success' => true,
                'data' => $result,
            ];

        } catch (GuzzleException $e) {
            // Extraer el body completo de la respuesta si está disponible (Guzzle trunca getMessage())
            $errorMsg = $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                try {
                    $body = $e->getResponse()->getBody()->getContents();
                    $decoded = json_decode($body, true);
                    $errorMsg = $decoded['error'] ?? $decoded['message'] ?? $body;
                } catch (\Throwable $ignored) {}
            }

            Logger::error("Error enviando planillas: {$errorMsg}", [
                'cantidad' => count($planillas),
            ]);

            return [
                'success' => false,
                'error' => $errorMsg,
            ];
        }
    }

    /**
     * Envía planillas con reintentos y backoff exponencial.
     *
     * @param array $planillas Planillas a enviar
     * @param int $maxReintentos Número máximo de reintentos (default: 3)
     * @param int $delayBaseSegundos Delay base en segundos para backoff (default: 5)
     * @return array Resultado con success, data/error, e intentos realizados
     */
    public function enviarPlanillasConRetry(array $planillas, int $maxReintentos = 3, int $delayBaseSegundos = 5, array $metadata = []): array
    {
        $intento = 0;
        $ultimoError = null;

        while ($intento <= $maxReintentos) {
            $intento++;

            if ($intento > 1) {
                // Backoff exponencial: 5s, 10s, 20s...
                $delay = $delayBaseSegundos * pow(2, $intento - 2);
                Logger::info("Reintento {$intento}/{$maxReintentos} en {$delay} segundos...");
                sleep($delay);
            }

            $resultado = $this->enviarPlanillas($planillas, $metadata);

            if ($resultado['success'] ?? false) {
                if ($intento > 1) {
                    Logger::info("Batch exitoso después de {$intento} intentos");
                }
                $resultado['intentos'] = $intento;
                return $resultado;
            }

            $ultimoError = $resultado['error'] ?? 'Error desconocido';
            Logger::warning("Intento {$intento} fallido: {$ultimoError}");
        }

        Logger::error("Batch fallido después de {$maxReintentos} reintentos", [
            'ultimo_error' => $ultimoError,
            'planillas' => count($planillas),
        ]);

        return [
            'success' => false,
            'error' => $ultimoError,
            'intentos' => $intento,
            'agotados_reintentos' => true,
        ];
    }


    /**
     * Verifica la conexión con el servidor de producción.
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->client->get('api/ferrawin/status');
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Logger::error("Error verificando conexión con producción: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Realiza una petición GET.
     */
    public function get(string $endpoint): array
    {
        try {
            $response = $this->client->get($endpoint);
            $result = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (GuzzleException $e) {
            Logger::error("Error en GET {$endpoint}: {$e->getMessage()}");

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Realiza una petición POST.
     */
    public function post(string $endpoint, array $data): array
    {
        try {
            $response = $this->client->post($endpoint, [
                'json' => $data,
            ]);
            $result = json_decode($response->getBody()->getContents(), true);

            return $result;
        } catch (GuzzleException $e) {
            throw new \Exception("Error en POST {$endpoint}: {$e->getMessage()}");
        }
    }

    /**
     * Obtiene los códigos de planillas existentes en el servidor destino.
     */
    public function getCodigosExistentes(): array
    {
        $response = $this->get('api/ferrawin/codigos-existentes');

        if (!($response['success'] ?? false)) {
            Logger::error("Error obteniendo códigos existentes: " . ($response['error'] ?? 'desconocido'));
            return [];
        }

        return $response['data']['codigos'] ?? [];
    }

    /**
     * Obtiene los códigos de planillas existentes CON conteo de elementos.
     * Se usa para detectar planillas modificadas (diferente número de elementos).
     *
     * @return array Mapa de código => conteo de elementos
     */
    public function getCodigosConConteo(): array
    {
        $response = $this->get('api/ferrawin/codigos-existentes?con_conteo=1');

        if (!($response['success'] ?? false)) {
            Logger::error("Error obteniendo códigos con conteo: " . ($response['error'] ?? 'desconocido'));
            return [];
        }

        return $response['data']['planillas'] ?? [];
    }
}
