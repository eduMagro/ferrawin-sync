<?php

namespace FerrawinSync;

use PDO;

/**
 * Consultas a la base de datos FerraWin.
 */
class FerrawinQuery
{
    /**
     * Obtiene los códigos de planillas con opciones flexibles.
     *
     * @param array $opciones [
     *   'dias_atras' => int,       // Días hacia atrás desde hoy
     *   'fecha_desde' => string,   // Fecha inicio (Y-m-d)
     *   'fecha_hasta' => string,   // Fecha fin (Y-m-d)
     *   'año' => int,              // Año específico
     *   'limite' => int,           // Límite de planillas
     *   'desde_codigo' => string,  // Código desde el que empezar (para retomar sync)
     * ]
     */
    public static function getCodigosPlanillas(array $opciones = []): array
    {
        $pdo = Database::getConnection();

        // Determinar rango de fechas
        if (isset($opciones['año'])) {
            $fechaDesde = $opciones['año'] . '-01-01';
            $fechaHasta = $opciones['año'] . '-12-31';
        } elseif (isset($opciones['fecha_desde'])) {
            $fechaDesde = $opciones['fecha_desde'];
            $fechaHasta = $opciones['fecha_hasta'] ?? date('Y-m-d');
        } else {
            $diasAtras = $opciones['dias_atras'] ?? 7;
            $fechaDesde = date('Y-m-d', strtotime("-{$diasAtras} days"));
            $fechaHasta = date('Y-m-d');
        }

        $limite = $opciones['limite'] ?? null;
        $desdeCodigo = $opciones['desde_codigo'] ?? null;

        // Construir SQL con TOP si hay límite (SQL Server)
        // En SQL Server: SELECT DISTINCT TOP N (no TOP N DISTINCT)
        $topClause = $limite ? "TOP ({$limite})" : "";

        // Filtro adicional para empezar desde un código específico
        // Como ordenamos DESC, "desde_codigo" significa códigos <= al especificado
        $filtroDesde = '';
        if ($desdeCodigo) {
            // Escapar el código para SQL
            $desdeCodigo = str_replace("'", "''", $desdeCodigo);
            $filtroDesde = "AND (ZCONTA + '-' + ZCODIGO) <= '{$desdeCodigo}'";
        }

        $sql = "
            SELECT DISTINCT {$topClause}
                ZCONTA + '-' + ZCODIGO as codigo
            FROM ORD_HEAD
            WHERE ZFECHA >= CONVERT(datetime, '{$fechaDesde}', 120)
              AND ZFECHA <= CONVERT(datetime, '{$fechaHasta}', 120)
              {$filtroDesde}
            ORDER BY codigo DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $codigos = [];
        while ($row = $stmt->fetch()) {
            $codigos[] = $row->codigo;
        }

        Logger::info("Códigos de planillas obtenidos", [
            'fecha_desde' => $fechaDesde,
            'fecha_hasta' => $fechaHasta,
            'desde_codigo' => $desdeCodigo ?? 'ninguno',
            'limite' => $limite ?? 'sin límite',
            'total' => count($codigos),
        ]);

        return $codigos;
    }

    /**
     * Obtiene estadísticas de la base de datos para planificar migración.
     */
    public static function getEstadisticas(): array
    {
        $pdo = Database::getConnection();

        // Total de planillas
        $stmt = $pdo->query("SELECT COUNT(DISTINCT ZCONTA + '-' + ZCODIGO) as total FROM ORD_HEAD");
        $totalPlanillas = $stmt->fetch()->total;

        // Planillas por año
        $stmt = $pdo->query("
            SELECT
                YEAR(ZFECHA) as año,
                COUNT(DISTINCT ZCONTA + '-' + ZCODIGO) as planillas
            FROM ORD_HEAD
            WHERE ZFECHA IS NOT NULL
            GROUP BY YEAR(ZFECHA)
            ORDER BY año DESC
        ");
        $porAño = $stmt->fetchAll();

        // Total de elementos (barras)
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM ORD_BAR");
        $totalElementos = $stmt->fetch()->total;

        // Rango de fechas
        $stmt = $pdo->query("SELECT MIN(ZFECHA) as primera, MAX(ZFECHA) as ultima FROM ORD_HEAD WHERE ZFECHA IS NOT NULL");
        $fechas = $stmt->fetch();

        return [
            'total_planillas' => $totalPlanillas,
            'total_elementos' => $totalElementos,
            'fecha_primera' => $fechas->primera,
            'fecha_ultima' => $fechas->ultima,
            'por_año' => $porAño,
        ];
    }

    /**
     * Obtiene solo la cabecera de una planilla (sin elementos).
     */
    public static function getCabeceraPlanilla(string $codigo): ?object
    {
        $partes = explode('-', $codigo, 2);

        if (count($partes) !== 2) {
            return null;
        }

        [$zconta, $zcodigo] = $partes;

        $pdo = Database::getConnection();

        $sql = "
            SELECT
                p.ZCODCLI as codigo_cliente,
                p.ZCLIENTE as nombre_cliente,
                p.ZCODIGO as codigo_obra,
                p.ZNOMBRE as nombre_obra,
                p.ZPOBLA as ensamblado,
                oh.ZMODULO as seccion,
                oh.ZFECHA as fecha,
                oh.ZNOMBRE as descripcion_planilla
            FROM ORD_HEAD oh
            LEFT JOIN PROJECT p ON oh.ZCODOBRA = p.ZCODIGO
            WHERE oh.ZCONTA = :zconta AND oh.ZCODIGO = :zcodigo
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'zconta' => $zconta,
            'zcodigo' => $zcodigo,
        ]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Obtiene todos los datos de una planilla específica.
     */
    public static function getDatosPlanilla(string $codigo): array
    {
        $partes = explode('-', $codigo, 2);

        if (count($partes) !== 2) {
            Logger::warning("Código de planilla inválido: {$codigo}");
            return [];
        }

        [$zconta, $zcodigo] = $partes;

        $pdo = Database::getConnection();

        $sql = "
            SELECT
                p.ZCODCLI as codigo_cliente,
                p.ZCLIENTE as nombre_cliente,
                p.ZCODIGO as codigo_obra,
                p.ZNOMBRE as nombre_obra,
                p.ZPOBLA as ensamblado,
                oh.ZMODULO as seccion,
                oh.ZFECHA as fecha,
                oh.ZNOMBRE as descripcion_planilla,
                ob.ZCODLIN as fila,
                od.ZSITUACION as descripcion_fila,
                ob.ZMARCA as marca,
                ob.ZDIAMETRO as diametro,
                ob.ZCODMODELO as figura,
                ob.ZLONGTESTD as longitud,
                ob.ZNUMBEND as dobles_barra,
                ob.ZCANTIDAD as barras,
                ob.ZPESOTESTD as peso,
                ob.ZFIGURA as zfigura,
                COALESCE(pd.ZETIQUETA, '') as etiqueta
            FROM ORD_BAR ob
            LEFT JOIN ORD_HEAD oh ON ob.ZCONTA = oh.ZCONTA AND ob.ZCODIGO = oh.ZCODIGO
            LEFT JOIN ORD_DET od ON ob.ZCONTA = od.ZCONTA AND ob.ZCODIGO = od.ZCODIGO
                AND ob.ZORDEN = od.ZORDEN AND ob.ZCODLIN = od.ZCODLIN
            LEFT JOIN PROJECT p ON oh.ZCODOBRA = p.ZCODIGO
            LEFT JOIN PROD_DETO pd ON ob.ZCONTA = pd.ZCONTA AND ob.ZCODIGO = pd.ZCODPLA
                AND ob.ZCODLIN = pd.ZCODLIN AND ob.ZELEMENTO = pd.ZELEMENTO
            WHERE ob.ZCONTA = :zconta AND ob.ZCODIGO = :zcodigo
            ORDER BY ob.ZCODLIN, ob.ZELEMENTO
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'zconta' => $zconta,
            'zcodigo' => $zcodigo,
        ]);

        $datos = $stmt->fetchAll();

        Logger::debug("Datos de planilla obtenidos", [
            'codigo' => $codigo,
            'elementos' => count($datos),
        ]);

        return $datos;
    }

    /**
     * Formatea los datos para enviar a la API.
     * Si no hay elementos, obtiene solo la cabecera.
     */
    public static function formatearParaApi(array $datos, string $codigo): array
    {
        // Si no hay elementos, obtener solo la cabecera
        if (empty($datos)) {
            $cabecera = self::getCabeceraPlanilla($codigo);

            if (!$cabecera) {
                return [];
            }

            return [
                'codigo' => $codigo,
                'descripcion' => $cabecera->descripcion_planilla ?? null,
                'seccion' => $cabecera->seccion ?? null,
                'ensamblado' => $cabecera->ensamblado ?? null,
                'fecha_creacion_ferrawin' => $cabecera->fecha ?? null,
                'elementos' => [],
                'sin_elementos' => true,
                // Datos de cliente/obra para resolver en el servidor
                'codigo_cliente' => $cabecera->codigo_cliente ?? '',
                'nombre_cliente' => $cabecera->nombre_cliente ?? '',
                'codigo_obra' => $cabecera->codigo_obra ?? '',
                'nombre_obra' => $cabecera->nombre_obra ?? '',
            ];
        }

        $primerElemento = $datos[0];

        $elementos = [];
        foreach ($datos as $row) {
            $elementos[] = [
                'codigo_cliente' => $row->codigo_cliente ?? '',
                'nombre_cliente' => $row->nombre_cliente ?? '',
                'codigo_obra' => $row->codigo_obra ?? '',
                'nombre_obra' => $row->nombre_obra ?? '',
                'ensamblado' => $row->ensamblado ?? '',
                'seccion' => $row->seccion ?? '',
                'descripcion_planilla' => $row->descripcion_planilla ?? '',
                'fila' => $row->fila ?? '',
                'descripcion_fila' => $row->descripcion_fila ?? '',
                'marca' => $row->marca ?? '',
                'diametro' => (int)($row->diametro ?? 0),
                'figura' => $row->figura ?? '',
                'longitud' => (float)($row->longitud ?? 0),
                'dobles_barra' => (int)($row->dobles_barra ?? 0),
                'barras' => (int)($row->barras ?? 0),
                'peso' => (float)($row->peso ?? 0),
                'dimensiones' => self::construirDimensiones($row),
                'etiqueta' => $row->etiqueta ?? '',
            ];
        }

        return [
            'codigo' => $codigo,
            'descripcion' => $primerElemento->descripcion_planilla ?? null,
            'seccion' => $primerElemento->seccion ?? null,
            'ensamblado' => $primerElemento->ensamblado ?? null,
            'fecha_creacion_ferrawin' => $primerElemento->fecha ?? null,
            'elementos' => $elementos,
        ];
    }

    /**
     * Construye el campo dimensiones.
     */
    private static function construirDimensiones($row): string
    {
        $numDobleces = (int)($row->dobles_barra ?? 0);

        if ($numDobleces === 0) {
            // Redondear y formatear sin decimales innecesarios
            return self::formatearNumero($row->longitud ?? 0);
        }

        $zfigura = $row->zfigura ?? '';

        if (!empty($zfigura) && strpos($zfigura, "\t") !== false) {
            return trim($zfigura);
        }

        return $zfigura ?: self::formatearNumero($row->longitud ?? 0);
    }

    /**
     * Formatea un número eliminando decimales innecesarios.
     * 300.000000 -> "300"
     * 300.5 -> "300.5"
     */
    private static function formatearNumero($valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        $num = (float)$valor;
        $rounded = round($num, 2);

        // Si es un entero, mostrar sin decimales
        if ($rounded == (int)$rounded) {
            return (string)(int)$rounded;
        }

        // Si tiene decimales significativos, mostrarlos
        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }

    /**
     * Obtiene los datos de ensamblaje de una planilla desde PROD_DETO.
     *
     * Los ensamblajes son agrupaciones de elementos que forman estructuras
     * como pilares, vigas, zunchos, etc.
     */
    public static function getEnsamblajes(string $codigo): array
    {
        $partes = explode('-', $codigo, 2);

        if (count($partes) !== 2) {
            return [];
        }

        [$zconta, $zcodigo] = $partes;

        $pdo = Database::getConnection();

        // Primero verificamos si hay datos de producción para esta planilla
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total FROM PROD_DETO
            WHERE ZCONTA = :zconta AND ZCODPLA = :zcodigo
        ");
        $stmt->execute(['zconta' => $zconta, 'zcodigo' => $zcodigo]);

        if ($stmt->fetch()->total === 0) {
            Logger::debug("Sin datos de ensamblaje para planilla", ['codigo' => $codigo]);
            return [];
        }

        // Obtener ensamblajes únicos (agrupados por ZETIQUETAC)
        $sql = "
            SELECT DISTINCT
                ZETIQUETAC as etiqueta_codigo,
                ZSITUACION as nombre
            FROM PROD_DETO
            WHERE ZCONTA = :zconta AND ZCODPLA = :zcodigo
            ORDER BY ZETIQUETAC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['zconta' => $zconta, 'zcodigo' => $zcodigo]);
        $ensamblajes = $stmt->fetchAll();

        $resultado = [];

        foreach ($ensamblajes as $ensamblaje) {
            // Obtener elementos de cada ensamblaje
            $sqlElementos = "
                SELECT
                    ZELEMENTO as elemento,
                    ZDIAMETRO as diametro,
                    ZCODMODELO as figura,
                    ZCANTIDAD as cantidad,
                    ZLONGITUD as longitud,
                    ZPESO as peso,
                    ZMEMBERS as miembros,
                    ZBARMEMBER as barras_miembro,
                    ZTIPO as tipo_acero,
                    ZMAQUINA as maquina,
                    ZCOTAS as cotas
                FROM PROD_DETO
                WHERE ZCONTA = :zconta
                  AND ZCODPLA = :zcodigo
                  AND ZETIQUETAC = :etiqueta
                ORDER BY ZELEMENTO
            ";

            $stmtElem = $pdo->prepare($sqlElementos);
            $stmtElem->execute([
                'zconta' => $zconta,
                'zcodigo' => $zcodigo,
                'etiqueta' => $ensamblaje->etiqueta_codigo,
            ]);
            $elementos = $stmtElem->fetchAll();

            $elementosFormateados = [];
            $pesoTotal = 0;
            $cantidadTotal = 0;

            foreach ($elementos as $elem) {
                $elementosFormateados[] = [
                    'elemento' => trim($elem->elemento ?? ''),
                    'diametro' => (int)($elem->diametro ?? 0),
                    'figura' => trim($elem->figura ?? ''),
                    'cantidad' => (int)($elem->cantidad ?? 0),
                    'longitud' => (float)($elem->longitud ?? 0),
                    'peso' => (float)($elem->peso ?? 0),
                    'miembros' => (int)($elem->miembros ?? 1),
                    'barras_miembro' => (int)($elem->barras_miembro ?? 0),
                    'tipo_acero' => trim($elem->tipo_acero ?? ''),
                    'maquina' => trim($elem->maquina ?? ''),
                    'cotas' => trim($elem->cotas ?? ''),
                ];
                $pesoTotal += (float)($elem->peso ?? 0);
                $cantidadTotal += (int)($elem->cantidad ?? 0);
            }

            $resultado[] = [
                'etiqueta' => trim($ensamblaje->etiqueta_codigo ?? ''),
                'nombre' => trim($ensamblaje->nombre ?? ''),
                'elementos' => $elementosFormateados,
                'resumen' => [
                    'total_elementos' => count($elementosFormateados),
                    'cantidad_total' => $cantidadTotal,
                    'peso_total' => round($pesoTotal, 2),
                ],
            ];
        }

        Logger::debug("Ensamblajes obtenidos", [
            'codigo' => $codigo,
            'ensamblajes' => count($resultado),
        ]);

        return $resultado;
    }

    /**
     * Formatea los datos incluyendo ensamblajes para enviar a la API.
     */
    public static function formatearParaApiConEnsamblajes(array $datos, string $codigo): array
    {
        $resultado = self::formatearParaApi($datos, $codigo);

        if (empty($resultado)) {
            return [];
        }

        // Añadir datos de ensamblaje si existen (desde PROD_DETO)
        $ensamblajes = self::getEnsamblajes($codigo);

        if (!empty($ensamblajes)) {
            $resultado['ensamblajes'] = $ensamblajes;
            $resultado['tiene_ensamblajes'] = true;
        } else {
            $resultado['ensamblajes'] = [];
            $resultado['tiene_ensamblajes'] = false;
        }

        // Siempre añadir composición de entidades (desde ORD_DET + ORD_BAR)
        $resultado['entidades'] = self::getComposicionEntidades($codigo);

        return $resultado;
    }

    /**
     * Obtiene la composición de entidades/ensamblajes desde ORD_DET + ORD_BAR.
     *
     * Cada entidad representa un elemento estructural (pilar, punzonamiento, viga, etc.)
     * con su marca, situación, cantidad y los elementos que lo componen.
     */
    public static function getComposicionEntidades(string $codigo): array
    {
        $partes = explode('-', $codigo, 2);

        if (count($partes) !== 2) {
            return [];
        }

        [$zconta, $zcodigo] = $partes;

        $pdo = Database::getConnection();

        // Obtener las entidades (líneas de ORD_DET) con COTAS de PROD_DETI
        $sqlEntidades = "
            SELECT
                od.ZCODLIN,
                od.ZORDEN,
                od.ZMARCA as marca,
                od.ZSITUACION as situacion,
                od.ZCANTIDAD as cantidad,
                od.ZMEMBERS as miembros,
                od.ZDIAMETRO as diametro_principal,
                od.ZCODMODELO as modelo,
                od.ZTIPO as tipo,
                di.ZCOTAS as cotas,
                di.ZLONGITUD as longitud_ensamblaje
            FROM ORD_DET od
            LEFT JOIN PROD_DETI di ON od.ZCONTA = di.ZCONTA
                AND od.ZCODIGO = di.ZCODPLA
                AND od.ZCODLIN = di.ZCODLIN
            WHERE od.ZCONTA = :zconta AND od.ZCODIGO = :zcodigo
            ORDER BY od.ZCODLIN
        ";

        $stmt = $pdo->prepare($sqlEntidades);
        $stmt->execute(['zconta' => $zconta, 'zcodigo' => $zcodigo]);
        $entidades = $stmt->fetchAll();

        $resultado = [];

        foreach ($entidades as $entidad) {
            // Obtener los elementos (barras/estribos) de esta entidad
            $sqlElementos = "
                SELECT
                    ob.ZELEMENTO as elemento,
                    ob.ZCANTIDAD as cantidad,
                    ob.ZDIAMETRO as diametro,
                    ob.ZLONGTESTD as longitud,
                    ob.ZNUMBEND as dobleces,
                    ob.ZFIGURA as dimensiones,
                    ob.ZPESOTESTD as peso,
                    ob.ZSTRBENT as tipo_forma,
                    ob.ZCODMODELO as figura,
                    ob.ZOBJETO as zobjeto
                FROM ORD_BAR ob
                WHERE ob.ZCONTA = :zconta
                  AND ob.ZCODIGO = :zcodigo
                  AND ob.ZCODLIN = :zcodlin
                ORDER BY ob.ZELEMENTO
            ";

            $stmtElem = $pdo->prepare($sqlElementos);
            $stmtElem->execute([
                'zconta' => $zconta,
                'zcodigo' => $zcodigo,
                'zcodlin' => $entidad->ZCODLIN,
            ]);
            $elementos = $stmtElem->fetchAll();

            // Clasificar elementos por tipo
            $barras = [];
            $estribos = [];
            $pesoTotal = 0;
            $longitudMaxima = 0;

            foreach ($elementos as $elem) {
                $dobleces = (int)($elem->dobleces ?? 0);
                $tipoForma = trim($elem->tipo_forma ?? '');

                $longitud = (float)($elem->longitud ?? 0);
                $dimensionesRaw = trim($elem->dimensiones ?? '');

                $elementoFormateado = [
                    'elemento' => trim($elem->elemento ?? ''),
                    'cantidad' => (int)($elem->cantidad ?? 0),
                    'diametro' => (int)($elem->diametro ?? 0),
                    'longitud' => $longitud,
                    'peso' => (float)($elem->peso ?? 0),
                    'figura' => trim($elem->figura ?? ''),
                    'zobjeto' => $elem->zobjeto ?? null,
                ];

                $pesoTotal += (float)($elem->peso ?? 0);

                // Si tiene dobleces, es un estribo; si no, es una barra recta
                if ($dobleces > 0 || $tipoForma === 'Doblado') {
                    $elementoFormateado['dobleces'] = $dobleces;
                    $elementoFormateado['dimensiones'] = $dimensionesRaw;
                    // Parsear secuencia de doblado si existe
                    if (!empty($dimensionesRaw)) {
                        $elementoFormateado['secuencia_doblado'] = self::parsearSecuenciaDoblado($dimensionesRaw);
                    }
                    $estribos[] = $elementoFormateado;
                } else {
                    // Las barras también pueden tener patillas (1 doblez)
                    if (!empty($dimensionesRaw)) {
                        $elementoFormateado['dimensiones'] = $dimensionesRaw;
                        $elementoFormateado['secuencia_doblado'] = self::parsearSecuenciaDoblado($dimensionesRaw);
                    }
                    $barras[] = $elementoFormateado;
                    if ($longitud > $longitudMaxima) {
                        $longitudMaxima = $longitud;
                    }
                }
            }

            $distribucion = self::calcularDistribucion($barras, $estribos, $longitudMaxima);

            // Obtener longitud desde PROD_DETI si está disponible
            $longitudEnsamblaje = (float)($entidad->longitud_ensamblaje ?? 0);
            if ($longitudEnsamblaje <= 0) {
                $longitudEnsamblaje = $longitudMaxima;
            }

            $resultado[] = [
                'linea' => trim($entidad->ZCODLIN ?? ''),
                'marca' => trim($entidad->marca ?? ''),
                'situacion' => trim($entidad->situacion ?? ''),
                'cantidad' => (int)($entidad->cantidad ?? 1),
                'miembros' => (int)($entidad->miembros ?? 1),
                'modelo' => trim($entidad->modelo ?? ''),
                'cotas' => trim($entidad->cotas ?? ''),
                'composicion' => [
                    'barras' => $barras,
                    'estribos' => $estribos,
                ],
                'distribucion' => $distribucion,
                'resumen' => [
                    'total_barras' => count($barras),
                    'total_estribos' => count($estribos),
                    'peso_total' => round($pesoTotal, 2),
                    'longitud_ensamblaje' => $longitudEnsamblaje,
                ],
            ];
        }

        Logger::debug("Composición de entidades obtenida", [
            'codigo' => $codigo,
            'entidades' => count($resultado),
        ]);

        return $resultado;
    }


    /**
     * Parsea la secuencia de doblado de ZFIGURA.
     *
     * Formato de entrada: "345\t90d\t30" (separado por tabs)
     * - Números sin sufijo = longitud en mm
     * - Xd = ángulo de doblez en grados (positivo o negativo)
     * - Xr = radio de doblado en mm
     *
     * @return array Array de segmentos para dibujar
     */
    private static function parsearSecuenciaDoblado(string $figura): array
    {
        if (empty($figura)) {
            return [];
        }

        // Separar por tabs
        $partes = preg_split('/\t/', $figura);
        $segmentos = [];

        foreach ($partes as $parte) {
            $parte = trim($parte);
            if (empty($parte)) {
                continue;
            }

            // Detectar tipo de segmento
            if (preg_match('/^(-?\d+\.?\d*)d$/', $parte, $matches)) {
                // Ángulo de doblez (ej: "90d", "-90d")
                $segmentos[] = [
                    'tipo' => 'doblez',
                    'angulo' => (float)$matches[1],
                ];
            } elseif (preg_match('/^(\d+\.?\d*)r$/', $parte, $matches)) {
                // Radio (ej: "12.0r")
                $segmentos[] = [
                    'tipo' => 'radio',
                    'valor' => (float)$matches[1],
                ];
            } elseif (is_numeric($parte)) {
                // Longitud recta en mm
                $segmentos[] = [
                    'tipo' => 'longitud',
                    'valor' => (float)$parte,
                ];
            }
        }

        return $segmentos;
    }

    private static function calcularDistribucion(array $barras, array $estribos, float $longitudTotal): array
    {
        $distribucion = [
            "longitud_total" => $longitudTotal,
            "armadura_longitudinal" => [],
            "armadura_transversal" => [],
        ];
        $posicion = 0;
        foreach ($barras as $barra) {
            $posicionLabel = $posicion < 2 ? "superior" : ($posicion < 4 ? "inferior" : "piel");
            $distribucion["armadura_longitudinal"][] = [
                "posicion" => $posicionLabel,
                "cantidad" => $barra["cantidad"],
                "diametro" => $barra["diametro"],
                "longitud" => $barra["longitud"],
            ];
            $posicion++;
        }
        foreach ($estribos as $estribo) {
            $cantidad = $estribo["cantidad"];
            $separacionAprox = ($longitudTotal > 0 && $cantidad > 1) ? round($longitudTotal / $cantidad) : 0;
            $distribucion["armadura_transversal"][] = [
                "cantidad" => $cantidad,
                "diametro" => $estribo["diametro"],
                "separacion_aprox_cm" => $separacionAprox,
                "dobleces" => $estribo["dobleces"] ?? 0,
                "forma" => $estribo["dimensiones"] ?? "",
            ];
        }
        return $distribucion;
    }
}