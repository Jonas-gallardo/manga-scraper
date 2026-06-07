<?php
/**
 * src/Controllers/ViewerController.php
 *
 * Controller for the comic image viewer.
 * Serves images with caching, or returns JSON with page listings.
 *
 * Modes:
 *   ?comic_id=ID          → JSON with page listing
 *   ?comic_id=ID&cover=1  → Serves first image (cover)
 *   ?comic_id=ID&page=N   → Serves page N (1-indexed)
 *   ?file=PATH            → Serves file by absolute path (legacy)
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class ViewerController extends BaseController
{
    /** @var array<string, string> MIME types for images */
    private array $mimeTypes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** @var array<string> Allowed image extensions */
    private array $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Handle all viewer requests, dispatching to the appropriate mode.
     */
    public function handle(): void
    {
        if (isset($_GET['comic_id'])) {
            $id = (int) $_GET['comic_id'];

            if (isset($_GET['cover'])) {
                $this->serveCover($id);
            } elseif (isset($_GET['page'])) {
                $this->servePage($id, (int) $_GET['page']);
            } else {
                $this->serveJsonListing($id);
            }
        } elseif (isset($_GET['file'])) {
            $this->serveLegacyFile($_GET['file']);
        } else {
            http_response_code(400);
            echo 'Parámetros insuficientes. Use ?comic_id=ID o ?file=path';
            exit;
        }
    }

    /**
     * Get comic pages from database and disk.
     */
    private function getComicPages(int $id): ?array
    {
        $pdo = $this->getPDO();
        $stmt = $pdo->prepare('SELECT * FROM comics_descargados WHERE id_fuente = ?');
        $stmt->execute([$id]);
        $comic = $stmt->fetch();

        if (!$comic || !$comic['ruta_carpeta'] || !is_dir($comic['ruta_carpeta'])) {
            return null;
        }

        $files = \escanear_imagenes($comic['ruta_carpeta']);
        return ['comic' => $comic, 'files' => $files];
    }

    /**
     * Serve the cover image (first page) of a comic.
     */
    private function serveCover(int $id): void
    {
        $result = $this->getComicPages($id);
        if (!$result || empty($result['files'])) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'No hay imágenes disponibles']);
            exit;
        }

        $this->serveImageFile($result['files'][0]);
    }

    /**
     * Serve a specific page of a comic (1-indexed).
     */
    private function servePage(int $id, int $pageNum): void
    {
        $pageIdx = $pageNum - 1; // 1-indexed → 0-indexed
        $result = $this->getComicPages($id);
        if (!$result || !isset($result['files'][$pageIdx])) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Página no encontrada']);
            exit;
        }

        $this->serveImageFile($result['files'][$pageIdx]);
    }

    /**
     * Serve JSON listing of all pages for a comic.
     */
    private function serveJsonListing(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $result = $this->getComicPages($id);
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

    /**
     * Serve an image file with caching headers (legacy mode).
     */
    private function serveLegacyFile(string $file): void
    {
        $realPath = realpath($file);
        $downloadsReal = realpath(DOWNLOADS_DIR);

        if ($realPath === false || strpos($realPath, $downloadsReal) !== 0) {
            header('HTTP/1.0 403 Forbidden');
            echo 'Acceso denegado';
            exit;
        }

        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExts) || !is_file($realPath)) {
            header('HTTP/1.0 404 Not Found');
            echo 'Archivo no encontrado';
            exit;
        }

        $this->serveImageFile($realPath);
    }

    /**
     * Serve an image file with proper caching headers.
     * Supports ETag-based 304 Not Modified responses.
     */
    private function serveImageFile(string $filepath): void
    {
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mime = $this->mimeTypes[$ext] ?? 'application/octet-stream';
        $etag = md5_file($filepath);
        $lastModified = gmdate('D, d M Y H:i:s T', filemtime($filepath));

        header("Content-Type: $mime");
        header("Content-Length: " . filesize($filepath));
        header("Cache-Control: public, max-age=86400");
        header("ETag: \"$etag\"");
        header("Last-Modified: $lastModified");

        // Support 304 Not Modified
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        readfile($filepath);
        exit;
    }
}
