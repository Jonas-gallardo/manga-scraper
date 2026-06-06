<?php
/**
 * dashboard.php
 *
 * API AJAX que devuelve estadísticas generales del sistema.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

try {
    // ── Totales generales ──
    $stats = [];

    // Total de cómics
    $stats['total_comics'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM comics_descargados'
    )->fetchColumn();

    // Por estado
    $stmt = $pdo->query(
        "SELECT estado, COUNT(*) as cantidad FROM comics_descargados GROUP BY estado"
    );
    $stats['por_estado'] = [];
    while ($row = $stmt->fetch()) {
        $stats['por_estado'][$row['estado']] = (int) $row['cantidad'];
    }

    // Total de páginas descargadas
    $stats['total_paginas_ok'] = (int) $pdo->query(
        'SELECT COALESCE(SUM(paginas_ok), 0) FROM comics_descargados'
    )->fetchColumn();

    // Total de páginas con error
    $stats['total_paginas_fail'] = (int) $pdo->query(
        'SELECT COALESCE(SUM(paginas_fail), 0) FROM comics_descargados'
    )->fetchColumn();

    // Total de páginas (potencial)
    $stats['total_paginas_total'] = (int) $pdo->query(
        'SELECT COALESCE(SUM(total_paginas), 0) FROM comics_descargados'
    )->fetchColumn();

    // Tamaño total en disco
    $stats['tamano_total_bytes'] = (int) $pdo->query(
        'SELECT COALESCE(SUM(tamano_bytes), 0) FROM comics_descargados'
    )->fetchColumn();
    $stats['tamano_total_formateado'] = formatear_bytes($stats['tamano_total_bytes']);

    // Universos distintos
    $stats['total_universos'] = (int) $pdo->query(
        'SELECT COUNT(DISTINCT universo) FROM comics_descargados WHERE universo IS NOT NULL'
    )->fetchColumn();

    // Últimas descargas
    $stmt = $pdo->query(
        'SELECT id_fuente, titulo, universo, paginas_ok, total_paginas, estado, fecha_descarga
         FROM comics_descargados
         ORDER BY fecha_descarga DESC
         LIMIT 5'
    );
    $stats['ultimas_descargas'] = $stmt->fetchAll();

    // Top universos
    $stmt = $pdo->query(
        'SELECT universo, COUNT(*) as cantidad
         FROM comics_descargados
         WHERE universo IS NOT NULL
         GROUP BY universo
         ORDER BY cantidad DESC
         LIMIT 10'
    );
    $stats['top_universos'] = $stmt->fetchAll();

    // Información del directorio de descargas
    $stats['ruta_descargas'] = DOWNLOADS_DIR;
    $stats['descargas_existe'] = is_dir(DOWNLOADS_DIR);

    if (is_dir(DOWNLOADS_DIR)) {
        $total_size = 0;
        $total_files = 0;
        $ri = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(DOWNLOADS_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($ri as $file) {
            $total_size += $file->getSize();
            $total_files++;
        }
        $stats['tamano_real_disco'] = $total_size;
        $stats['tamano_real_formateado'] = formatear_bytes($total_size);
        $stats['archivos_en_disco'] = $total_files;
    } else {
        $stats['tamano_real_disco'] = 0;
        $stats['tamano_real_formateado'] = '0 B';
        $stats['archivos_en_disco'] = 0;
    }

    // ── Estadísticas de Taxonomías ──
    // Idiomas más comunes
    $stmt = $pdo->query(
        "SELECT JSON_UNQUOTE(JSON_EXTRACT(taxonomias, '$.idioma')) as idioma,
                COUNT(*) as cantidad
         FROM comics_descargados
         WHERE taxonomias IS NOT NULL AND JSON_VALID(taxonomias)
           AND JSON_EXTRACT(taxonomias, '$.idioma') IS NOT NULL
         GROUP BY JSON_EXTRACT(taxonomias, '$.idioma')
         ORDER BY cantidad DESC
         LIMIT 5"
    );
    $stats['top_idiomas'] = $stmt->fetchAll();

    // Cómics con taxonomías vs sin taxonomías
    $stats['comics_con_taxonomias'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM comics_descargados WHERE taxonomias IS NOT NULL AND JSON_VALID(taxonomias)'
    )->fetchColumn();
    $stats['comics_sin_taxonomias'] = $stats['total_comics'] - $stats['comics_con_taxonomias'];

    // Total de etiquetas únicas procesadas
    $stmt = $pdo->query(
        "SELECT DISTINCT JSON_EXTRACT(taxonomias, '$.etiquetas') as etiquetas
         FROM comics_descargados
         WHERE taxonomias IS NOT NULL AND JSON_VALID(taxonomias)"
    );
    $tags_unicas = [];
    while ($row = $stmt->fetch()) {
        $tags = json_decode($row['etiquetas'], true);
        if (is_array($tags)) {
            foreach ($tags as $t) {
                $tags_unicas[mb_strtolower(trim($t))] = true;
            }
        }
    }
    $stats['total_etiquetas_unicas'] = count($tags_unicas);

    // Actividad reciente (logs)
    $stmt = $pdo->query(
        'SELECT tipo, COUNT(*) as cantidad, DATE(fecha) as dia
         FROM log_descargas
         WHERE fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY dia, tipo
         ORDER BY dia DESC'
    );
    $stats['actividad_reciente'] = $stmt->fetchAll();

    // Tasa de éxito
    $total_pags = $stats['total_paginas_ok'] + $stats['total_paginas_fail'];
    $stats['tasa_exito'] = $total_pags > 0
        ? round(($stats['total_paginas_ok'] / $total_pags) * 100, 1)
        : 0;

    echo json_encode([
        'success' => true,
        'stats'   => $stats,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
    ]);
}

function formatear_bytes($bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
