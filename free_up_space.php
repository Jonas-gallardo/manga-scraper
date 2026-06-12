<?php
/**
 * free_up_space.php
 *
 * API para optimizar espacio en disco: listar cómics ya publicados en WordPress
 * que aún tienen imágenes locales, y borrarlas en lote conservando los registros
 * en BD para evitar duplicados.
 *
 * FLUJO:
 *   GET  ?action=list          → Lista cómics publicados con imágenes aún en disco
 *   POST ?action=free (ids[])  → Borra imágenes de los IDs seleccionados
 *   GET  ?action=stats         → Estadísticas de espacio recuperable
 *
 * REGLAS:
 *   - Solo aplica a cómics con wp_publish_status = 'published'
 *   - Borra el directorio completo de imágenes del disco
 *   - Conserva el registro en comics_descargados (marca imagenes_eliminadas = 1)
 *   - No aplica a cómics con imagenes_eliminadas = 1 (ya fueron liberados)
 *
 * @package ScrapApp
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$action = trim($_GET['action'] ?? $_POST['action'] ?? 'list');

// ── Asegurar que la columna imagenes_eliminadas existe ──
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM comics_descargados LIKE 'imagenes_eliminadas'");
    if (!$stmt->fetch()) {
        $pdo->exec(
            "ALTER TABLE comics_descargados
             ADD COLUMN imagenes_eliminadas TINYINT(1) DEFAULT 0
             COMMENT '1 = imágenes borradas del disco tras publicar en WP (optimizar espacio)'
             AFTER wp_publish_error"
        );
    }
} catch (Exception $e) {
    // Columna ya existe o no se pudo crear — continuar
}

// ─────────────────────────────────────────────────────────
// ACCIÓN: list — Listar cómics publicados con imágenes aún en disco
// ─────────────────────────────────────────────────────────
if ($action === 'list') {
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $per_page = max(1, min(100, (int) ($_GET['per_page'] ?? 50)));
    $search   = trim($_GET['search'] ?? '');
    $offset   = ($page - 1) * $per_page;

    try {
        $where = [
            "c.wp_publish_status = 'published'",
            "(c.imagenes_eliminadas IS NULL OR c.imagenes_eliminadas = 0)",
            "c.ruta_carpeta IS NOT NULL",
            "c.ruta_carpeta != ''",
        ];
        $params = [];

        if ($search) {
            $where[] = '(c.titulo LIKE :search OR c.id_fuente LIKE :search2)';
            $params[':search']  = "%{$search}%";
            $params[':search2'] = "%{$search}%";
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where);

        // ── Total ──
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comics_descargados c {$where_clause}");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        // ── Página actual ──
        $stmt = $pdo->prepare(
            "SELECT c.id_fuente, c.titulo, c.universo, c.autor, c.total_paginas,
                    c.tamano_bytes, c.ruta_carpeta, c.wp_post_id, c.fecha_descarga,
                    c.wp_publish_status, COALESCE(c.imagenes_eliminadas, 0) as imagenes_eliminadas
             FROM comics_descargados c
             {$where_clause}
             ORDER BY c.tamano_bytes DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $comics = $stmt->fetchAll();

        // ── Verificar si el directorio realmente existe en disco ──
        foreach ($comics as &$comic) {
            $comic['dir_existe'] = ($comic['ruta_carpeta'] && is_dir($comic['ruta_carpeta']));
            $comic['tamano_formateado'] = format_bytes((int) $comic['tamano_bytes']);

            // Si el directorio no existe pero la BD dice que tiene imágenes, corregir
            if (!$comic['dir_existe'] && !$comic['imagenes_eliminadas']) {
                $comic['estado_real'] = 'directorio_faltante';
            } else {
                $comic['estado_real'] = 'candidato';
            }
        }
        unset($comic);

        // ── Total de espacio recuperable ──
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(c.tamano_bytes), 0) FROM comics_descargados c {$where_clause}");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $espacio_total = (int) $stmt->fetchColumn();

        echo json_encode([
            'success'        => true,
            'comics'         => $comics,
            'total'          => $total,
            'page'           => $page,
            'per_page'       => $per_page,
            'total_pages'    => ceil($total / max(1, $per_page)),
            'espacio_recuperable' => $espacio_total,
            'espacio_formateado'  => format_bytes($espacio_total),
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error al consultar: ' . $e->getMessage(),
        ]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────
// ACCIÓN: free — Borrar imágenes de los IDs seleccionados
// ─────────────────────────────────────────────────────────
if ($action === 'free' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_ids = $_POST['ids'] ?? '';
    $ids = [];

    if (is_array($raw_ids)) {
        $ids = array_map('intval', $raw_ids);
    } elseif (is_string($raw_ids)) {
        $ids = array_map('intval', explode(',', $raw_ids));
    }

    $ids = array_filter(array_unique($ids), fn($id) => $id > 0);

    if (empty($ids)) {
        echo json_encode([
            'success' => false,
            'message' => 'No se proporcionaron IDs válidos',
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    try {
        // ── Cargar datos de los cómics solicitados ──
        $stmt = $pdo->prepare(
            "SELECT id_fuente, titulo, ruta_carpeta, tamano_bytes, wp_publish_status,
                    COALESCE(imagenes_eliminadas, 0) as imagenes_eliminadas
             FROM comics_descargados
             WHERE id_fuente IN ({$placeholders})"
        );
        $stmt->execute(array_values($ids));
        $comics = $stmt->fetchAll();

        if (empty($comics)) {
            echo json_encode([
                'success' => false,
                'message' => 'Ninguno de los IDs proporcionados existe en la base de datos',
            ]);
            exit;
        }

        $results = [
            'total_solicitados'   => count($ids),
            'procesados'          => 0,
            'liberados'           => 0,
            'omitidos'            => 0,
            'errores'             => 0,
            'espacio_liberado'    => 0,
            'no_encontrados'      => 0,
            'detalles'            => [],
        ];

        $stmt_update = $pdo->prepare(
            "UPDATE comics_descargados
             SET imagenes_eliminadas = 1,
                 ruta_carpeta = NULL,
                 tamano_bytes = 0,
                 paginas_ok = 0
             WHERE id_fuente = ?"
        );

        $stmt_log = $pdo->prepare(
            "INSERT INTO log_descargas (id_fuente, tipo, mensaje) VALUES (?, ?, ?)"
        );

        foreach ($comics as $comic) {
            $id_fuente   = (int) $comic['id_fuente'];
            $titulo      = $comic['titulo'];
            $ruta        = $comic['ruta_carpeta'];
            $tamano_orig = (int) $comic['tamano_bytes'];
            $status_wp   = $comic['wp_publish_status'];
            $ya_eliminado = (int) $comic['imagenes_eliminadas'];

            $results['procesados']++;

            // ── Validar que esté publicado ──
            if ($status_wp !== 'published') {
                $results['omitidos']++;
                $results['detalles'][] = [
                    'id_fuente' => $id_fuente,
                    'titulo'    => $titulo,
                    'resultado' => 'omitido',
                    'motivo'    => "No publicado (estado: {$status_wp})",
                ];
                continue;
            }

            // ── Ya fue liberado ──
            if ($ya_eliminado) {
                $results['omitidos']++;
                $results['detalles'][] = [
                    'id_fuente' => $id_fuente,
                    'titulo'    => $titulo,
                    'resultado' => 'omitido',
                    'motivo'    => 'Ya fue liberado anteriormente',
                ];
                continue;
            }

            $archivos_eliminados = 0;
            $error_borrado = null;

            // ── Eliminar directorio de imágenes ──
            if ($ruta && is_dir($ruta)) {
                try {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($ruta, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $file) {
                        if ($file->isFile()) {
                            if (@unlink($file->getRealPath())) {
                                $archivos_eliminados++;
                            }
                        } elseif ($file->isDir()) {
                            @rmdir($file->getRealPath());
                        }
                    }
                    @rmdir($ruta);
                } catch (Exception $e) {
                    $error_borrado = $e->getMessage();
                }
            } else {
                // Directorio no existe, pero procedemos a marcar igual
                $error_borrado = 'Directorio no encontrado en disco';
            }

            // ── Actualizar BD ──
            if ($error_borrado === null || $archivos_eliminados > 0) {
                $stmt_update->execute([$id_fuente]);
                $results['liberados']++;
                $results['espacio_liberado'] += $tamano_orig;

                $stmt_log->execute([
                    $id_fuente,
                    'info',
                    "Optimización: {$archivos_eliminados} archivos eliminados. Espacio liberado: " . format_bytes($tamano_orig) . ". Publicado como WP Post #{$comic['wp_post_id']}."
                ]);

                $results['detalles'][] = [
                    'id_fuente'          => $id_fuente,
                    'titulo'             => $titulo,
                    'resultado'          => 'liberado',
                    'archivos_eliminados' => $archivos_eliminados,
                    'espacio_liberado'   => $tamano_orig,
                    'espacio_formateado' => format_bytes($tamano_orig),
                ];
            } else {
                // Marcar igual en BD porque el directorio no existe
                $stmt_update->execute([$id_fuente]);
                $results['liberados']++;

                $stmt_log->execute([
                    $id_fuente,
                    'info',
                    "Optimización: directorio no existente, registro marcado como liberado. ({$error_borrado})"
                ]);

                $results['detalles'][] = [
                    'id_fuente' => $id_fuente,
                    'titulo'    => $titulo,
                    'resultado' => 'liberado_sin_dir',
                    'motivo'    => $error_borrado,
                ];
            }
        }

        log_to_file("OPTIMIZACIÓN: {$results['liberados']} cómics liberados, " . format_bytes($results['espacio_liberado']) . " recuperados");

        echo json_encode([
            'success'            => true,
            'message'            => "{$results['liberados']} cómics liberados, " . format_bytes($results['espacio_liberado']) . " recuperados",
            'results'            => $results,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error al liberar espacio: ' . $e->getMessage(),
        ]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────
// ACCIÓN: stats — Estadísticas rápidas
// ─────────────────────────────────────────────────────────
if ($action === 'stats') {
    try {
        $stmt = $pdo->query(
            "SELECT
                COUNT(*) as candidatos,
                COALESCE(SUM(tamano_bytes), 0) as espacio_total
             FROM comics_descargados
             WHERE wp_publish_status = 'published'
               AND (imagenes_eliminadas IS NULL OR imagenes_eliminadas = 0)
               AND ruta_carpeta IS NOT NULL
               AND ruta_carpeta != ''"
        );
        $candidatos = $stmt->fetch();

        $stmt2 = $pdo->query(
            "SELECT COUNT(*) as ya_liberados
             FROM comics_descargados
             WHERE wp_publish_status = 'published'
               AND imagenes_eliminadas = 1"
        );
        $ya_liberados = $stmt2->fetch();

        echo json_encode([
            'success'                  => true,
            'candidatos'               => (int) $candidatos['candidatos'],
            'espacio_recuperable'      => (int) $candidatos['espacio_total'],
            'espacio_formateado'       => format_bytes((int) $candidatos['espacio_total']),
            'ya_liberados'             => (int) $ya_liberados['ya_liberados'],
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error al consultar estadísticas: ' . $e->getMessage(),
        ]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────
// Acción no reconocida
// ─────────────────────────────────────────────────────────
echo json_encode([
    'success' => false,
    'message' => "Acción no reconocida: '{$action}'. Usar ?action=list, ?action=free (POST), o ?action=stats",
]);

// ─────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────

function format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * Escribe en archivo de log rotativo.
 */
function log_to_file(string $message): void {
    $dir = defined('LOG_DIR') ? LOG_DIR : (__DIR__ . '/logs');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = $dir . '/scraper.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
