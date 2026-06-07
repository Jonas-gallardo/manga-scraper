<?php

declare(strict_types=1);

namespace ScrapApp\Infrastructure;

/**
 * FileManager.php
 *
 * Gestiona operaciones del sistema de archivos relacionadas con cómics:
 * creación de directorios, descarga de imágenes, conversión WebP,
 * limpieza de descargas incompletas, etc.
 *
 * Reemplaza las funciones globales crear_directorio_comic(),
 * descargar_imagen(), cleanup_incomplete_comic() (parte filesystem)
 * de scraper.php, y referencia a escanear_imagenes() y
 * convertir_comic_a_webp() de config.php.
 *
 * @package ScrapApp
 * @subpackage Infrastructure
 */
class FileManager
{
    private HttpClient $httpClient;
    private \Closure $progressFn;
    private int $maxRetries;
    private int $retryWaitSeconds;

    /**
     * @param HttpClient $httpClient Cliente HTTP para descargas
     * @param callable $progressFn Función callback para enviar progreso
     * @param int $maxRetries Máximo de reintentos
     * @param int $retryWaitSeconds Segundos de espera entre reintentos
     */
    public function __construct(
        HttpClient $httpClient,
        callable $progressFn,
        int $maxRetries = 2,
        int $retryWaitSeconds = 10
    ) {
        $this->httpClient = $httpClient;
        $this->progressFn = $progressFn;
        $this->maxRetries = defined('MAX_RETRIES') ? MAX_RETRIES : $maxRetries;
        $this->retryWaitSeconds = defined('RETRY_WAIT_SECONDS') ? RETRY_WAIT_SECONDS : $retryWaitSeconds;
    }

    /**
     * Crea el directorio para almacenar las imágenes de un cómic.
     * Retorna la ruta del directorio o null si falla.
     *
     * @param int $id ID del cómic
     * @param string $titulo Título del cómic
     * @return string|null Ruta absoluta del directorio, o null si falla
     */
    public function createComicDirectory(int $id, string $titulo): ?string
    {
        $sanitized = preg_replace('#[/:*?"<>|]#', '_', $titulo);
        $sanitized = substr($sanitized, 0, 200);
        $dirName = "[{$id}] {$sanitized}";
        $baseDir = defined('DOWNLOADS_DIR') ? DOWNLOADS_DIR : __DIR__ . '/../../descargas';

        // Asegurar que el directorio base exista y sea escribible
        if (!is_dir($baseDir)) {
            if (!@mkdir($baseDir, 0777, true)) {
                $cb = $this->progressFn;
                $cb([
                    'type'    => 'error',
                    'message' => "No se pudo crear el directorio base: $baseDir"
                ]);
                return null;
            }
        } elseif (!is_writable($baseDir)) {
            @chmod($baseDir, 0777);
            if (!is_writable($baseDir)) {
                $cb = $this->progressFn;
                $cb([
                    'type'    => 'error',
                    'message' => "El directorio base no tiene permisos de escritura: $baseDir"
                ]);
                return null;
            }
        }

        $fullPath = $baseDir . '/' . $dirName;
        if (!is_dir($fullPath)) {
            if (!@mkdir($fullPath, 0777, true)) {
                $cb = $this->progressFn;
                $cb([
                    'type'    => 'error',
                    'message' => "No se pudo crear el directorio: $fullPath"
                ]);
                return null;
            }
        } elseif (!is_writable($fullPath)) {
            @chmod($fullPath, 0777);
        }

        @chmod($fullPath, 0777);
        return $fullPath;
    }

    /**
     * Descarga una imagen desde $url y la guarda en $path.
     * Si devuelve HTML, extrae la URL de imagen y reintenta.
     * Detecta el formato real de la imagen.
     *
     * @param string $url URL de la imagen
     * @param string $path Ruta de destino
     * @param int $retries Contador de reintentos (referencia)
     * @return bool True si la descarga fue exitosa
     */
    public function downloadImage(string $url, string $path, int &$retries = 0): bool
    {
        $cb = $this->progressFn;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_HEADER          => true,
            CURLOPT_TIMEOUT         => defined('CURL_TIMEOUT') ? CURL_TIMEOUT : 30,
            CURLOPT_CONNECTTIMEOUT  => defined('CURL_CONNECT_TIMEOUT') ? CURL_CONNECT_TIMEOUT : 10,
            CURLOPT_MAXREDIRS       => defined('CURL_MAXREDIRS') ? CURL_MAXREDIRS : 5,
            CURLOPT_SSL_VERIFYPEER  => defined('CURL_SSL_VERIFY') ? CURL_SSL_VERIFY : false,
            CURLOPT_USERAGENT       => defined('USER_AGENT')
                ? USER_AGENT
                : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_ENCODING        => '',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || in_array($httpCode, [403, 429, 503], true)) {
            $retries++;
            if ($retries <= $this->maxRetries) {
                $reason = $response === false ? "cURL: $error" : "HTTP $httpCode";
                $cb([
                    'type'    => 'warning',
                    'message' => "Error imagen ($reason), reintento $retries/{$this->maxRetries} en {$this->retryWaitSeconds} s..."
                ]);
                sleep($this->retryWaitSeconds);
                return $this->downloadImage($url, $path, $retries);
            }
            $cb([
                'type'    => 'error',
                'message' => "Imagen falló tras {$this->maxRetries} reintentos: $url"
            ]);
            return false;
        }

        if ($httpCode === 404) {
            $cb([
                'type'    => 'warning',
                'message' => "Imagen no encontrada (HTTP 404): $url"
            ]);
            return false;
        }

        $body = substr($response, $headerSize);

        // Si recibimos HTML, extraer la primera imagen
        if ($contentType && stripos($contentType, 'text/html') !== false) {
            $cb([
                'type'    => 'info',
                'message' => "La URL devolvió HTML, extrayendo imagen del DOM..."
            ]);
            $imgUrl = null;
            if (preg_match('#<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp))["\'][^>]*>#si', $body, $m)) {
                $imgUrl = $m[1];
            } elseif (preg_match('#<img[^>]+src=["\']([^"\']+)["\'][^>]*>#si', $body, $m)) {
                $imgUrl = $m[1];
            }
            if ($imgUrl) {
                if (strpos($imgUrl, 'http') !== 0) {
                    $parsed = parse_url($url);
                    $base = $parsed['scheme'] . '://' . $parsed['host'];
                    $imgUrl = $base . ($imgUrl[0] === '/' ? '' : '/') . $imgUrl;
                }
                return $this->downloadImage($imgUrl, $path, $retries);
            }
            $cb([
                'type'    => 'warning',
                'message' => "No se pudo extraer URL de imagen del HTML"
            ]);
            return false;
        }

        // Determinar extensión real por Content-Type
        $ext = 'jpg';
        if ($contentType) {
            $map = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
            ];
            foreach ($map as $ct => $e) {
                if (stripos($contentType, $ct) !== false) {
                    $ext = $e;
                    break;
                }
            }
        }

        // Ajustar extensión del archivo
        $path = preg_replace('/\.\w+$/', '.' . $ext, $path);

        $bytes = file_put_contents($path, $body);
        if ($bytes === false) {
            $cb([
                'type'    => 'error',
                'message' => "Error al escribir archivo: $path"
            ]);
            return false;
        }

        return true;
    }

    /**
     * Elimina un directorio de cómic incompleto recursivamente.
     *
     * @param string $dirPath Ruta del directorio a eliminar
     */
    public function removeDirectoryRecursive(string $dirPath): void
    {
        if (!$dirPath || !is_dir($dirPath)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dirPath);

        $cb = $this->progressFn;
        $cb([
            'type'    => 'warning',
            'message' => "🗑 Carpeta incompleta eliminada: {$dirPath}"
        ]);
    }

    /**
     * Escanea un directorio y devuelve imágenes ordenadas.
     * Reemplaza a glob() que falla con caracteres no-ASCII.
     *
     * @param string $dir Ruta absoluta del directorio
     * @return array<string> Array de rutas completas de imágenes
     */
    public function scanImages(string $dir): array
    {
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $files = [];

        if (!is_dir($dir)) {
            return $files;
        }

        $handle = opendir($dir);
        if ($handle === false) {
            return $files;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, $extensions)) {
                    $files[] = $path;
                }
            }
        }
        closedir($handle);

        natsort($files);
        return array_values($files);
    }

    /**
     * Verifica si un archivo de imagen existe para una página en un directorio.
     *
     * @param string $dirPath Ruta del directorio
     * @param string $filename Nombre del archivo sin extensión (ej: "001")
     * @return string|null Ruta del archivo si existe, null si no
     */
    public function findImageFile(string $dirPath, string $filename): ?string
    {
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
            $testPath = $dirPath . '/' . $filename . '.' . $ext;
            if (is_file($testPath)) {
                return $testPath;
            }
        }
        return null;
    }

    /**
     * Convierte todas las imágenes de un directorio a WebP.
     * Delega a la función global convertir_comic_a_webp() de config.php
     * para mantener compatibilidad.
     *
     * @param string $dirPath Ruta del directorio del cómic
     * @param int $quality Calidad WebP (1-100)
     * @return array{converted: int, skipped: int, failed: int, bytes_original: int, bytes_webp: int, bytes_ahorrados: int}
     */
    public function convertToWebP(string $dirPath, int $quality = 85): array
    {
        if (function_exists('convertir_comic_a_webp')) {
            return convertir_comic_a_webp($dirPath, $quality);
        }

        // Implementación fallback básica
        $stats = [
            'converted'      => 0,
            'skipped'        => 0,
            'failed'         => 0,
            'bytes_original' => 0,
            'bytes_webp'     => 0,
            'bytes_ahorrados' => 0,
        ];

        if (!is_dir($dirPath)) {
            return $stats;
        }

        $images = $this->scanImages($dirPath);
        foreach ($images as $filepath) {
            $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
            if ($ext === 'webp' || $ext === 'avif') {
                $stats['skipped']++;
                continue;
            }

            $info = pathinfo($filepath);
            $webpPath = $info['dirname'] . '/' . $info['filename'] . '.webp';

            if (file_exists($webpPath)) {
                $stats['skipped']++;
                $origSize = @filesize($filepath);
                $webpSize = @filesize($webpPath);
                $stats['bytes_original'] += $origSize ?: 0;
                $stats['bytes_webp'] += $webpSize ?: 0;
                @unlink($filepath);
                continue;
            }

            $originalSize = @filesize($filepath);
            if ($originalSize === false || $originalSize === 0) {
                $stats['failed']++;
                continue;
            }

            // Intentar con GD
            if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
                $img = @imagecreatefromstring(file_get_contents($filepath));
                if ($img !== false) {
                    if (@imagewebp($img, $webpPath, $quality)) {
                        $stats['converted']++;
                        $stats['bytes_original'] += $originalSize;
                        $stats['bytes_webp'] += @filesize($webpPath) ?: 0;
                        @unlink($filepath);
                        imagedestroy($img);
                        continue;
                    }
                    imagedestroy($img);
                }
            }

            $stats['failed']++;
        }

        $stats['bytes_ahorrados'] = $stats['bytes_original'] - $stats['bytes_webp'];
        return $stats;
    }
}
