<?php
require 'vendor/autoload.php';
FerrawinSync\Config::load();
$pdo = FerrawinSync\Database::getConnection();

$sql = "
SELECT
    YEAR(oh.ZFECHA) as año,
    COUNT(DISTINCT oh.ZCONTA + '-' + oh.ZCODIGO) as total_planillas,
    COUNT(DISTINCT CASE WHEN pd.ZCODPLA IS NOT NULL THEN oh.ZCONTA + '-' + oh.ZCODIGO END) as con_datos
FROM ORD_HEAD oh
LEFT JOIN PROD_DETO pd ON oh.ZCONTA = pd.ZCONTA AND oh.ZCODIGO = pd.ZCODPLA
GROUP BY YEAR(oh.ZFECHA)
ORDER BY año
";
$stmt = $pdo->query($sql);
echo "Año  | Total   | Con datos\n";
echo "-----+---------+----------\n";
while ($r = $stmt->fetch()) {
    printf("%d | %7d | %5d\n", $r->año, $r->total_planillas, $r->con_datos);
}
