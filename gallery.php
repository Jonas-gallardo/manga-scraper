<?php
/**
 * gallery.php
 *
 * API AJAX para la galería de cómics descargados.
 * Retorna JSON con el listado paginado y filtrado.
 *
 * MEJORAS:
 *   ✓ Filtro por rango de fechas (fecha_desde / fecha_hasta)
 *   ✓ Excluye automáticamente los mangas eliminados (mangas_eliminados)
 *   ✓ Retorna la lista de universos disponibles
 *   ✓ Revalidación automática de estado
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

// ── Parámetros ──
$page        = max(1, (int) ($_GET['page'] ?? 1));
$per_page    = max(1, min(50, (int) ($_GET['per_page'] ?? 20)));
$search      = trim($_GET['search'] ?? '');
$universo    = trim($_GET['universo'] ?? '');
$estado      = trim($_GET['estado'] ?? '');
$fecha_desde = trim($_GET['fecha_desde'] ?? '');
$fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
$sort        = in_array($_GET['sort'] ?? '', ['fecha_descarga', 'titulo', 'id_fuente', 'universo', 'total_paginas'])
               ? $_GET['sort'] : 'fecha_descarga';
$order       = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$solo_publicados = isset($_GET['solo_publicados']) && $_GET['solo_publicados'] === '1';

$offset = ($page - 1) * $per_page;

try {
    // ── Construir WHERE dinámico ──
    $where = [];
    $params = [];

    if ($search) {
        $where[] = '(c.titulo LIKE :search OR c.tags LIKE :search2 OR c.id_fuente LIKE :search3)';
        $params[':search'] = "%{$search}%";
        $params[':search2'] = "%{$search}%";
        $params[':search3'] = "%{$search}%";
    }

    if ($universo) {
        $where[] = 'c.universo = :universo';
        $params[':universo'] = $universo;
    }

    if ($estado) {
        $where[] = 'c.estado = :estado';
        $params[':estado'] = $estado;
    }

    // ── Filtro: solo publicados en WordPress ──
    if ($solo_publicados) {
        $where[] = "c.wp_publish_status = 'published'";
    }

    // ── Filtro por rango de fechas ──
    if ($fecha_desde) {
        $where[] = 'c.fecha_descarga >= :fecha_desde';
        $params[':fecha_desde'] = $fecha_desde . ' 00:00:00';
    }
    if ($fecha_hasta) {
        $where[] = 'c.fecha_descarga <= :fecha_hasta';
        $params[':fecha_hasta'] = $fecha_hasta . ' 23:59:59';
    }

    // ── Excluir mangas que están en la blacklist (eliminados) ──
    $where[] = 'c.id_fuente NOT IN (SELECT id_fuente FROM mangas_eliminados)';

    $where_clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Total de registros ──
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM comics_descargados c $where_clause");
    $stmt->execute($params);
    $total = (int) $stmt->fetch()['total'];

    // ── Consultar página actual ──
    $allowed_sort = ['fecha_descarga', 'titulo', 'id_fuente', 'universo', 'total_paginas'];
    if (!in_array($sort, $allowed_sort)) $sort = 'fecha_descarga';
    $order_dir = ($order === 'ASC') ? 'ASC' : 'DESC';

    $sql = "SELECT c.*,
                   COALESCE(c.tamano_bytes, 0) as tamano_bytes
            FROM comics_descargados c
            $where_clause
            ORDER BY c.$sort $order_dir
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $comics = $stmt->fetchAll();

    // ── Obtener portada y conteo real de archivos para cada cómic ──
    $stmt_repair = $pdo->prepare(
        'UPDATE comics_descargados SET estado = :nuevo, paginas_ok = :ok, paginas_fail = :fail WHERE id_fuente = :id'
    );

    foreach ($comics as &$comic) {
        $comic['portada'] = null;
        $comic['archivos_reales'] = 0;
        $comic['imagenes_eliminadas'] = (bool) ($comic['imagenes_eliminadas'] ?? false);

        // ── Parsear taxonomías JSON para el frontend ──
        if (!empty($comic['taxonomias'])) {
            $decoded = json_decode($comic['taxonomias'], true);
            if (is_array($decoded)) {
                $comic['taxonomias'] = $decoded;
            } else {
                $comic['taxonomias'] = null;
            }
        } else {
            $comic['taxonomias'] = null;
        }

        if ($comic['ruta_carpeta'] && is_dir($comic['ruta_carpeta'])) {
            $files = escanear_imagenes($comic['ruta_carpeta']);
            $comic['archivos_reales'] = count($files);

            if (!empty($files)) {
                $comic['portada'] = 'viewer.php?comic_id=' . $comic['id_fuente'] . '&cover=1';
            }

            // ── REVALIDACIÓN AUTOMÁTICA ──
            if ($comic['estado'] === 'error' && $comic['archivos_reales'] > 0) {
                $total_esperado = (int) $comic['total_paginas'];
                $nuevo_estado = ($comic['archivos_reales'] >= $total_esperado && $total_esperado > 0)
                    ? 'completo'
                    : 'parcial';

                $stmt_repair->execute([
                    ':nuevo' => $nuevo_estado,
                    ':ok'    => $comic['archivos_reales'],
                    ':fail'  => max(0, $total_esperado - $comic['archivos_reales']),
                    ':id'    => $comic['id_fuente'],
                ]);

                $comic['estado'] = $nuevo_estado;
                $comic['paginas_ok'] = $comic['archivos_reales'];
                $comic['paginas_fail'] = max(0, $total_esperado - $comic['archivos_reales']);
            }
        }

        $comic['tamano_formateado'] = formatear_bytes($comic['tamano_bytes']);
    }
    unset($comic);

    // ── Obtener lista de universos para filtros (excluyendo eliminados) ──
    $stmt_uni = $pdo->query(
        'SELECT DISTINCT c.universo FROM comics_descargados c
         WHERE c.universo IS NOT NULL
         AND c.id_fuente NOT IN (SELECT id_fuente FROM mangas_eliminados)
         ORDER BY c.universo'
    );
    $universos = $stmt_uni->fetchAll(PDO::FETCH_COLUMN);

    // ── Contar mangas eliminados (para mostrar en UI) ──
    $total_eliminados = (int) $pdo->query(
        'SELECT COUNT(*) FROM mangas_eliminados'
    )->fetchColumn();

    echo json_encode([
        'success'   => true,
        'comics'    => $comics,
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $per_page,
        'total_pages' => ceil($total / $per_page),
        'universos' => $universos,
        'total_eliminados' => $total_eliminados,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al consultar: ' . $e->getMessage()
    ]);
}

function formatear_bytes($bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
