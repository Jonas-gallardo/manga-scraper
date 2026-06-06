<?php
/**
 * delete_manga.php
 *
 * API AJAX para eliminar un manga del sistema.
 * - Mueve el registro a mangas_eliminados (blacklist permanente)
 * - Elimina los archivos del disco
 * - Elimina el registro de comics_descargados
 *
 * Método: POST
 * Parámetros:
 *   id_fuente  (int, obligatorio) - ID del manga a eliminar
 *   motivo     (string, opcional) - Motivo de eliminación
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Solo se aceptan peticiones POST'
    ]);
    exit;
}

$id_fuente = isset($_POST['id_fuente']) ? (int) $_POST['id_fuente'] : 0;
$motivo    = trim($_POST['motivo'] ?? 'Eliminado por usuario');

if ($id_fuente <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ID de manga no válido'
    ]);
    exit;
}

try {
    // ── 1. Obtener datos del manga antes de eliminarlo ──
    $stmt = $pdo->prepare(
        'SELECT id_fuente, titulo, universo, autor, total_paginas, paginas_ok, tamano_bytes, ruta_carpeta, fecha_descarga
         FROM comics_descargados WHERE id_fuente = ?'
    );
    $stmt->execute([$id_fuente]);
    $comic = $stmt->fetch();

    if (!$comic) {
        // Podría estar ya en mangas_eliminados
        echo json_encode([
            'success' => false,
            'message' => "El manga ID {$id_fuente} no existe en la base de datos"
        ]);
        exit;
    }

    $titulo        = $comic['titulo'];
    $universo      = $comic['universo'];
    $autor         = $comic['autor'];
    $total_paginas = (int) $comic['total_paginas'];
    $paginas_ok    = (int) $comic['paginas_ok'];
    $tamano_bytes  = (int) $comic['tamano_bytes'];
    $ruta_carpeta  = $comic['ruta_carpeta'];
    $fecha_origen  = $comic['fecha_descarga'];

    // ── 2. Insertar en mangas_eliminados (blacklist permanente) ──
    $stmt = $pdo->prepare(
        'INSERT INTO mangas_eliminados (id_fuente, titulo, universo, autor, total_paginas, paginas_ok, tamano_bytes, motivo, fecha_origen)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
         titulo = VALUES(titulo),
         universo = VALUES(universo),
         autor = VALUES(autor),
         total_paginas = VALUES(total_paginas),
         paginas_ok = VALUES(paginas_ok),
         tamano_bytes = VALUES(tamano_bytes),
         motivo = VALUES(motivo),
         fecha_eliminacion = NOW(),
         fecha_origen = COALESCE(fecha_origen, VALUES(fecha_origen))'
    );
    $stmt->execute([$id_fuente, $titulo, $universo, $autor, $total_paginas, $paginas_ok, $tamano_bytes, $motivo, $fecha_origen]);

    // ── 3. Eliminar archivos del disco ──
    $archivos_eliminados = 0;

    // Resolver ruta: usar ruta_carpeta de BD, o intentar reconstruir desde DOWNLOADS_DIR
    if (!$ruta_carpeta || !is_dir($ruta_carpeta)) {
        $fallback = defined('DOWNLOADS_DIR') ? DOWNLOADS_DIR . "/[{$id_fuente}] {$titulo}" : null;
        if ($fallback && is_dir($fallback)) {
            $ruta_carpeta = $fallback;
        }
    }

    if ($ruta_carpeta && is_dir($ruta_carpeta)) {
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($ruta_carpeta, RecursiveDirectoryIterator::SKIP_DOTS),
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
            @rmdir($ruta_carpeta);
        } catch (Exception $e) {
            // Si falla eliminación de archivos, continuamos con la BD
            error_log("delete_manga: error al eliminar archivos de ID {$id_fuente}: " . $e->getMessage());
        }
    }

    // ── 4. Eliminar de comics_descargados ──
    $stmt = $pdo->prepare('DELETE FROM comics_descargados WHERE id_fuente = ?');
    $stmt->execute([$id_fuente]);

    // ── 5. Registrar en log ──
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO log_descargas (id_fuente, tipo, mensaje) VALUES (?, ?, ?)'
        );
        $stmt->execute([$id_fuente, 'warning', "Manga eliminado: «{$titulo}» (ID {$id_fuente}). Motivo: {$motivo}. Archivos borrados: {$archivos_eliminados}"]);
    } catch (Exception $e) {
        // No interrumpir si falla el log
    }

    // ── 6. Registrar en log de archivo ──
    log_to_file("ELIMINADO ID {$id_fuente} - «{$titulo}» - Motivo: {$motivo} - Archivos: {$archivos_eliminados}");

    echo json_encode([
        'success'  => true,
        'message'  => "Manga «{$titulo}» (ID {$id_fuente}) eliminado correctamente",
        'id_fuente' => $id_fuente,
        'titulo'   => $titulo,
        'archivos_eliminados' => $archivos_eliminados,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar: ' . $e->getMessage()
    ]);
}

/**
 * Escribe en archivo de log rotativo (duplicado de config.php para aislar la función).
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
