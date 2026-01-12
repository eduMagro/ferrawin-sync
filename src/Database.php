<?php

namespace FerrawinSync;

use PDO;
use PDOException;

/**
 * Conexión a la base de datos FerraWin (SQL Server).
 */
class Database
{
    private static ?PDO $connection = null;

    /**
     * Obtiene la conexión PDO a FerraWin.
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::connect();
        }

        return self::$connection;
    }

    /**
     * Establece la conexión a FerraWin.
     */
    private static function connect(): void
    {
        $host = Config::ferrawin('host');
        $port = Config::ferrawin('port');
        $database = Config::ferrawin('database');
        $username = Config::ferrawin('username');
        $password = Config::ferrawin('password');

        $dsn = "sqlsrv:Server={$host},{$port};Database={$database};TrustServerCertificate=yes";

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
            ]);

            Logger::info("Conexión a FerraWin establecida", [
                'host' => $host,
                'database' => $database,
            ]);

        } catch (PDOException $e) {
            Logger::error("Error conectando a FerraWin: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Verifica si la conexión está activa.
     */
    public static function testConnection(): bool
    {
        try {
            $pdo = self::getConnection();
            $pdo->query("SELECT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Cierra la conexión.
     */
    public static function close(): void
    {
        self::$connection = null;
    }
}
