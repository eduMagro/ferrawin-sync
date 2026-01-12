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
            'timeout' => 120,
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

            return [
                'success' => true,
                'data' => $result,
            ];

        } catch (GuzzleException $e) {
            Logger::error("Error enviando planillas: {$e->getMessage()}", [
                'cantidad' => count($planillas),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
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
}
