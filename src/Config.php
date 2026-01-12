<?php

namespace FerrawinSync;

/**
 * Configuración centralizada del túnel de sincronización.
 */
class Config
{
    private static ?array $config = null;
    private static string $currentTarget = 'local'; // 'local' o 'production'

    public static function load(): void
    {
        if (self::$config !== null) {
            return;
        }

        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();

        self::$config = [
            'ferrawin' => [
                'host' => $_ENV['FERRAWIN_HOST'] ?? '192.168.0.7',
                'port' => $_ENV['FERRAWIN_PORT'] ?? '1433',
                'database' => $_ENV['FERRAWIN_DATABASE'] ?? 'FERRAWIN',
                'username' => $_ENV['FERRAWIN_USERNAME'] ?? 'sa',
                'password' => $_ENV['FERRAWIN_PASSWORD'] ?? '',
            ],
            'local' => [
                'url' => rtrim($_ENV['LOCAL_URL'] ?? 'http://127.0.0.1/manager/public/', '/') . '/',
                'token' => $_ENV['LOCAL_TOKEN'] ?? $_ENV['API_TOKEN'] ?? '',
            ],
            'production' => [
                'url' => rtrim($_ENV['PRODUCTION_URL'] ?? '', '/') . '/',
                'token' => $_ENV['PRODUCTION_TOKEN'] ?? $_ENV['API_TOKEN'] ?? '',
            ],
            'sync' => [
                'dias_atras' => (int)($_ENV['SYNC_DIAS_ATRAS'] ?? 7),
                'compress' => filter_var($_ENV['SYNC_COMPRESS'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
            'log' => [
                'level' => $_ENV['LOG_LEVEL'] ?? 'info',
            ],
        ];
    }

    /**
     * Establece el target de sincronización (local o production).
     */
    public static function setTarget(string $target): void
    {
        if (!in_array($target, ['local', 'production'])) {
            throw new \InvalidArgumentException("Target inválido: {$target}. Use 'local' o 'production'.");
        }
        self::$currentTarget = $target;
    }

    /**
     * Obtiene el target actual.
     */
    public static function getTarget(): string
    {
        return self::$currentTarget;
    }

    public static function get(string $key, $default = null)
    {
        self::load();

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public static function ferrawin(string $key = null)
    {
        return $key ? self::get("ferrawin.{$key}") : self::get('ferrawin');
    }

    /**
     * Obtiene la configuración del servidor destino actual.
     */
    public static function target(string $key = null)
    {
        $targetConfig = self::$currentTarget;
        return $key ? self::get("{$targetConfig}.{$key}") : self::get($targetConfig);
    }

    /**
     * @deprecated Usar target() en su lugar
     */
    public static function production(string $key = null)
    {
        return self::target($key);
    }
}
