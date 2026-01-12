<?php

namespace FerrawinSync;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Logger centralizado para el túnel de sincronización.
 */
class Logger
{
    private static ?MonologLogger $instance = null;

    public static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = new MonologLogger('ferrawin-sync');

            // Formato personalizado
            $formatter = new LineFormatter(
                "[%datetime%] %level_name%: %message% %context%\n",
                "Y-m-d H:i:s"
            );

            // Handler para archivo rotativo (mantiene 30 días)
            $fileHandler = new RotatingFileHandler(
                __DIR__ . '/../logs/sync.log',
                30,
                self::getLogLevel()
            );
            $fileHandler->setFormatter($formatter);
            self::$instance->pushHandler($fileHandler);

            // Handler para consola
            $consoleHandler = new StreamHandler('php://stdout', self::getLogLevel());
            $consoleHandler->setFormatter($formatter);
            self::$instance->pushHandler($consoleHandler);
        }

        return self::$instance;
    }

    private static function getLogLevel(): int
    {
        $level = strtolower(Config::get('log.level', 'info'));

        return match($level) {
            'debug' => MonologLogger::DEBUG,
            'info' => MonologLogger::INFO,
            'warning' => MonologLogger::WARNING,
            'error' => MonologLogger::ERROR,
            default => MonologLogger::INFO,
        };
    }

    public static function info(string $message, array $context = []): void
    {
        self::getInstance()->info($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::getInstance()->error($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, $context);
    }
}
