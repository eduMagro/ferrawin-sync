<?php

/**
 * Script para probar las conexiones antes de ejecutar la sincronización.
 */

require_once __DIR__ . '/vendor/autoload.php';

use FerrawinSync\Config;
use FerrawinSync\Logger;
use FerrawinSync\Database;
use FerrawinSync\ApiClient;

echo "=== Test de Conexiones FerraWin Sync ===\n\n";

// Cargar configuración
Config::load();

$allOk = true;

// 1. Test conexión FerraWin
echo "1. Probando conexión a FerraWin (SQL Server)...\n";
echo "   Host: " . Config::ferrawin('host') . "\n";
echo "   Puerto: " . Config::ferrawin('port') . "\n";
echo "   Base de datos: " . Config::ferrawin('database') . "\n";

try {
    if (Database::testConnection()) {
        echo "   ✓ Conexión exitosa\n\n";
    } else {
        echo "   ✗ Error: No se pudo conectar\n\n";
        $allOk = false;
    }
} catch (Throwable $e) {
    echo "   ✗ Error: {$e->getMessage()}\n\n";
    $allOk = false;
}

// 2. Test conexión producción
echo "2. Probando conexión a producción...\n";
echo "   URL: " . Config::production('url') . "\n";

try {
    $apiClient = new ApiClient();

    if ($apiClient->testConnection()) {
        echo "   ✓ Conexión exitosa\n\n";
    } else {
        echo "   ✗ Error: No se pudo conectar (verifica URL y token)\n\n";
        $allOk = false;
    }
} catch (Throwable $e) {
    echo "   ✗ Error: {$e->getMessage()}\n\n";
    $allOk = false;
}

// 3. Verificar extensión sqlsrv
echo "3. Verificando extensión PHP sqlsrv...\n";

if (extension_loaded('sqlsrv') || extension_loaded('pdo_sqlsrv')) {
    echo "   ✓ Extensión cargada\n\n";
} else {
    echo "   ✗ Extensión sqlsrv no encontrada\n";
    echo "   Instala los drivers de SQL Server para PHP:\n";
    echo "   https://docs.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server\n\n";
    $allOk = false;
}

// Resumen
echo "=== Resumen ===\n";

if ($allOk) {
    echo "✓ Todas las conexiones funcionan correctamente.\n";
    echo "Puedes ejecutar: php sync.php\n";
    exit(0);
} else {
    echo "✗ Hay problemas de conexión. Revisa la configuración.\n";
    exit(1);
}
