<?php
/**
 * viewer.php
 *
 * Visor de imágenes de cómics descargados.
 * Sirve imágenes con caché, o devuelve JSON con la lista de páginas.
 *
 * Modos:
 *   ?comic_id=ID              → JSON con lista de páginas
 *   ?comic_id=ID&cover=1      → Sirve la primera imagen (portada)
 *   ?comic_id=ID&page=N       → Sirve la página N (1-indexed)
 *   ?file=PATH                → Sirve archivo por ruta absoluta (legacy)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';

/**
 * Obtiene la lista ordenada de archivos de imagen para un comic.
 */
function get_comic_pages(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM comics_descargados WHERE id_fuente = ?');
    $stmt->execute([$id]);
    $comic = $stmt->fetch();

    if (!$comic || !$comic['ruta_carpeta'] || !is_dir($comic['ruta_carpeta'])) {
        return null;
    }

    $files = escanear_imagenes($comic['ruta_carpeta']);
    return ['comic' => $comic, 'files' => $files];
}

// ── Modo JSON: devolver lista de páginas de un cómic ──
if (isset($_GET['comic_id'])) {
    $id = (int) $_GET['comic_id'];

    // ── Detectar si las imágenes fueron eliminadas (optimización de espacio) ──
    $stmt = $pdo->prepare(
        'SELECT id_fuente, titulo, wp_post_id, imagenes_eliminadas
         FROM comics_descargados WHERE id_fuente = ?'
    );
    $stmt->execute([$id]);
    $comic_meta = $stmt->fetch();

    if ($comic_meta && !empty($comic_meta['imagenes_eliminadas'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'           => false,
            'imagenes_eliminadas' => true,
            'titulo'            => $comic_meta['titulo'],
            'wp_post_id'        => (int) $comic_meta['wp_post_id'],
            'message'           => 'Este cómic ya fue subido a WordPress/Gluglux y sus imágenes fueron eliminadas del almacenamiento local para optimizar espacio.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Modo portada (cover) ──
    if (isset($_GET['cover'])) {
        $result = get_comic_pages($pdo, $id);
        if (!$result || empty($result['files'])) {
            header('HTTP/1.0 404 Not Found');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'No hay imágenes disponibles']);
            exit;
        }

        $file = $result['files'][0];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime_types = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];
        $mime = $mime_types[$ext] ?? 'application/octet-stream';

        header("Content-Type: $mime");
        header("Content-Length: " . filesize($file));
        header("Cache-Control: public, max-age=86400");
        header("ETag: \"" . md5_file($file) . "\"");
        header("Last-Modified: " . gmdate('D, d M Y H:i:s T', filemtime($file)));

        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $req_etag = trim($_SERVER['HTTP_IF_NONE_MATCH'], '"');
            if ($req_etag === md5_file($file)) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }
        }

        readfile($file);
        exit;
    }

    // ── Modo página específica ──
    if (isset($_GET['page'])) {
        $page_idx = (int) $_GET['page'] - 1; // 1-indexed → 0-indexed
        $result = get_comic_pages($pdo, $id);
        if (!$result || !isset($result['files'][$page_idx])) {
            header('HTTP/1.0 404 Not Found');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Página no encontrada']);
            exit;
        }

        $file = $result['files'][$page_idx];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime_types = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];
        $mime = $mime_types[$ext] ?? 'application/octet-stream';

        header("Content-Type: $mime");
        header("Content-Length: " . filesize($file));
        header("Cache-Control: public, max-age=86400");
        header("ETag: \"" . md5_file($file) . "\"");
        header("Last-Modified: " . gmdate('D, d M Y H:i:s T', filemtime($file)));

        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $req_etag = trim($_SERVER['HTTP_IF_NONE_MATCH'], '"');
            if ($req_etag === md5_file($file)) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }
        }

        readfile($file);
        exit;
    }

    // ── Modo JSON (lista de páginas) ──
    header('Content-Type: application/json; charset=utf-8');

    $result = get_comic_pages($pdo, $id);
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Cómic no encontrado o carpeta no existe']);
        exit;
    }

    $paginas = [];
    foreach ($result['files'] as $idx => $file) {
        $paginas[] = [
            'path'     => $file,
            'url'      => 'viewer.php?comic_id=' . $id . '&page=' . ($idx + 1),
            'filename' => basename($file),
            'size'     => filesize($file),
        ];
    }

    echo json_encode([
        'success' => true,
        'comic'   => $result['comic'],
        'paginas' => $paginas,
        'total'   => count($paginas),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Modo imagen (legacy): servir archivo por ruta absoluta ──
if (isset($_GET['file'])) {
    $file = $_GET['file'];

    // Validar que el archivo esté dentro del directorio de descargas
    $real_path = realpath($file);
    $downloads_real = realpath(DOWNLOADS_DIR);

    if ($real_path === false || strpos($real_path, $downloads_real) !== 0) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Acceso denegado';
        exit;
    }

    // Verificar que sea una imagen
    $ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed) || !is_file($real_path)) {
        header('HTTP/1.0 404 Not Found');
        echo 'Archivo no encontrado';
        exit;
    }

    // Determinar Content-Type
    $mime_types = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];
    $mime = $mime_types[$ext] ?? 'application/octet-stream';

    // Cabeceras de caché
    $etag = md5_file($real_path);
    $last_modified = gmdate('D, d M Y H:i:s T', filemtime($real_path));

    header("Content-Type: $mime");
    header("Content-Length: " . filesize($real_path));
    header("Cache-Control: public, max-age=86400");
    header("ETag: \"$etag\"");
    header("Last-Modified: $last_modified");

    // Soporte para 304 Not Modified
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }

    readfile($real_path);
    exit;
}

// ── Sin parámetros ──
header('HTTP/1.0 400 Bad Request');
echo 'Parámetros insuficientes. Use ?comic_id=ID o ?file=path';
