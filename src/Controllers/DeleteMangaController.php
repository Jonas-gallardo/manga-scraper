<?php
/**
 * src/Controllers/DeleteMangaController.php
 *
 * Controller for deleting a manga from the system.
 * Moves the record to mangas_eliminados (permanent blacklist),
 * deletes files from disk, and removes from comics_descargados.
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class DeleteMangaController extends BaseController
{
    /**
     * Delete a manga by ID.
     * POST only. Expects: id_fuente (int), motivo (string, optional).
     */
    public function delete(): void
    {
        $this->requirePost();

        // Accept both 'id_fuente' (PHP-style) and 'comic_id' (JS-style)
        // from JSON body (sent by JS fetch) or POST form data
        $id_fuente = (int) ($this->jsonBody('id_fuente') ?: $this->jsonBody('comic_id') ?: $this->postParam('id_fuente') ?: $this->postParam('comic_id', 0));
        $motivo    = trim($this->postParam('motivo', 'Eliminado por usuario'));

        if ($id_fuente <= 0) {
            $this->json([
                'success' => false,
                'message' => 'ID de manga no válido',
            ], 400);
        }

        try {
            $pdo = $this->getPDO();

            // ── 1. Get comic data before deleting ──
            $stmt = $pdo->prepare(
                'SELECT id_fuente, titulo, universo, autor, total_paginas, paginas_ok, tamano_bytes, ruta_carpeta, fecha_descarga
                 FROM comics_descargados WHERE id_fuente = ?'
            );
            $stmt->execute([$id_fuente]);
            $comic = $stmt->fetch();

            if (!$comic) {
                $this->json([
                    'success' => false,
                    'message' => "El manga ID {$id_fuente} no existe en la base de datos",
                ], 404);
            }

            $titulo        = $comic['titulo'];
            $universo      = $comic['universo'];
            $autor         = $comic['autor'];
            $total_paginas = (int) $comic['total_paginas'];
            $paginas_ok    = (int) $comic['paginas_ok'];
            $tamano_bytes  = (int) $comic['tamano_bytes'];
            $ruta_carpeta  = $comic['ruta_carpeta'];
            $fecha_origen  = $comic['fecha_descarga'];

            // ── 2. Insert into mangas_eliminados (permanent blacklist) ──
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

            // ── 3. Delete files from disk ──
            $archivos_eliminados = 0;

            if (!$ruta_carpeta || !is_dir($ruta_carpeta)) {
                $fallback = defined('DOWNLOADS_DIR') ? DOWNLOADS_DIR . "/[{$id_fuente}] {$titulo}" : null;
                if ($fallback && is_dir($fallback)) {
                    $ruta_carpeta = $fallback;
                }
            }

            if ($ruta_carpeta && is_dir($ruta_carpeta)) {
                try {
                    $files = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($ruta_carpeta, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::CHILD_FIRST
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
                } catch (\Exception $e) {
                    \error_log("delete_manga: error al eliminar archivos de ID {$id_fuente}: " . $e->getMessage());
                }
            }

            // ── 4. Delete from comics_descargados ──
            $stmt = $pdo->prepare('DELETE FROM comics_descargados WHERE id_fuente = ?');
            $stmt->execute([$id_fuente]);

            // ── 5. Log to database ──
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO log_descargas (id_fuente, tipo, mensaje) VALUES (?, ?, ?)'
                );
                $stmt->execute([$id_fuente, 'warning', "Manga eliminado: «{$titulo}» (ID {$id_fuente}). Motivo: {$motivo}. Archivos borrados: {$archivos_eliminados}"]);
            } catch (\Exception $e) {
                // Don't interrupt if logging fails
            }

            // ── 6. Log to file ──
            $this->logToFile("ELIMINADO ID {$id_fuente} - «{$titulo}» - Motivo: {$motivo} - Archivos: {$archivos_eliminados}");

            $this->json([
                'success'  => true,
                'message'  => "Manga «{$titulo}» (ID {$id_fuente}) eliminado correctamente",
                'id_fuente' => $id_fuente,
                'titulo'   => $titulo,
                'archivos_eliminados' => $archivos_eliminados,
            ]);
        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'message' => 'Error al eliminar: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Write to log file with rotation support.
     */
    private function logToFile(string $message): void
    {
        $dir = defined('LOG_DIR') ? LOG_DIR : (__DIR__ . '/../../logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $file = $dir . '/scraper.log';
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
