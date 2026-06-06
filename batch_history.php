<?php
/**
 * batch_history.php
 *
 * API AJAX para el historial de ejecuciones batch.
 * Retorna JSON con los registros de batch_historial,
 * permitiendo re-ejecutar URLs ya procesadas.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

try {
    // ── Parámetros ──
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $per_page = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));
    $search   = trim($_GET['search'] ?? '');
    $offset   = ($page - 1) * $per_page;

    // ── Construir WHERE dinámico ──
    $where = [];
    $params = [];

    if ($search) {
        $where[] = '(url_base LIKE :search OR universo LIKE :search2)';
        $params[':search'] = "%{$search}%";
        $params[':search2'] = "%{$search}%";
    }

    $where_clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Total de registros ──
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM batch_historial $where_clause");
    $stmt->execute($params);
    $total = (int) $stmt->fetch()['total'];

    // ── Consultar página actual ──
    $sql = "SELECT *
            FROM batch_historial
            $where_clause
            ORDER BY fecha_ejecucion DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $registros = $stmt->fetchAll();

    // ── Formatear datos para el frontend ──
    foreach ($registros as &$row) {
        $row['completado_texto'] = $row['completado'] ? '✅ Completado' : '⏳ Interrumpido';
        $row['fecha_formateada'] = date('d/m/Y H:i', strtotime($row['fecha_ejecucion']));
        $row['resume_page'] = ($row['completado'] ? $row['ultima_pagina'] : $row['ultima_pagina']) + 1;
    }
    unset($row);

    echo json_encode([
        'success'    => true,
        'registros'  => $registros,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $per_page,
        'total_pages'=> ceil($total / $per_page),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al consultar historial: ' . $e->getMessage()
    ]);
}
