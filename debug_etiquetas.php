<?php
require 'vendor/autoload.php';

use FerrawinSync\Config;
use FerrawinSync\Database;

Config::load();
$pdo = Database::getConnection();

$codigo = $argv[1] ?? '2025-008770';
list($zconta, $zcodigo) = explode('-', $codigo);

echo "=== Planilla: $codigo ===\n\n";

$sql = "SELECT TOP 20
    ob.ZCODLIN as fila,
    COALESCE(pd.ZETIQUETA, '') as etiqueta,
    od.ZSITUACION as descripcion_fila,
    ob.ZMARCA as marca,
    ob.ZDIAMETRO as diametro
FROM ORD_BAR ob
LEFT JOIN ORD_DET od ON ob.ZCONTA = od.ZCONTA AND ob.ZCODIGO = od.ZCODIGO AND ob.ZCODLIN = od.ZCODLIN
LEFT JOIN PROD_DETO pd ON ob.ZCONTA = pd.ZCONTA AND ob.ZCODIGO = pd.ZCODPLA
WHERE ob.ZCONTA = ? AND ob.ZCODIGO = ?
ORDER BY ob.ZCODLIN";

$stmt = $pdo->prepare($sql);
$stmt->execute([$zconta, $zcodigo]);

echo "Fila | Etiqueta | Descripcion | Marca | Diametro\n";
echo str_repeat("-", 80) . "\n";

while ($row = $stmt->fetch()) {
    printf("%s | %s | %s | %s | %s\n",
        str_pad($row->fila ?? '', 4),
        str_pad($row->etiqueta ?: 'VACIO', 10),
        str_pad(substr($row->descripcion_fila ?? '', 0, 25), 25),
        str_pad($row->marca ?? '', 15),
        $row->diametro
    );
}

// Contar agrupaciones
echo "\n=== Resumen de agrupación ===\n";

$sql2 = "SELECT
    COALESCE(NULLIF(COALESCE(pd.ZETIQUETA, ''), ''), od.ZSITUACION, 'grupo_idx') as grupo,
    COUNT(*) as total
FROM ORD_BAR ob
LEFT JOIN ORD_DET od ON ob.ZCONTA = od.ZCONTA AND ob.ZCODIGO = od.ZCODIGO AND ob.ZCODLIN = od.ZCODLIN
LEFT JOIN PROD_DETO pd ON ob.ZCONTA = pd.ZCONTA AND ob.ZCODIGO = pd.ZCODPLA
WHERE ob.ZCONTA = ? AND ob.ZCODIGO = ?
GROUP BY COALESCE(NULLIF(COALESCE(pd.ZETIQUETA, ''), ''), od.ZSITUACION, 'grupo_idx')
ORDER BY grupo";

$stmt2 = $pdo->prepare($sql2);
$stmt2->execute([$zconta, $zcodigo]);

$totalGrupos = 0;
while ($row = $stmt2->fetch()) {
    echo sprintf("Grupo [%s]: %d elementos\n", $row->grupo ?: 'SIN GRUPO', $row->total);
    $totalGrupos++;
}
echo "\nTotal grupos (etiquetas padre): $totalGrupos\n";
