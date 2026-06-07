<?php

declare(strict_types=1);

namespace ScrapApp\Repositories;

/**
 * ComicRepository.php
 *
 * Repositorio para operaciones CRUD sobre la tabla comics_descargados
 * y tablas relacionadas (batch_historial, mangas_eliminados, log_descargas).
 *
 * Encapsula todas las consultas SQL que antes estaban dispersas en scraper.php.
 *
 * @package ScrapApp
 * @subpackage Repositories
 */
class ComicRepository
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Obtiene un cómic por su ID de fuente (para verificar existencia).
     *
     * @param int $id ID del cómic
     * @return array|null Registro del cómic o null si no existe
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_fuente, titulo, estado, ruta_carpeta, total_paginas, paginas_ok, paginas_fail, tamano_bytes
             FROM comics_descargados WHERE id_fuente = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Verifica si un cómic está en la blacklist (mangas_eliminados).
     *
     * @param int $id ID del cómic
     * @return bool True si está eliminado (no se debe descargar)
     */
    public function isDeleted(int $id): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id_fuente FROM mangas_eliminados WHERE id_fuente = ?'
            );
            $stmt->execute([$id]);
            return (bool) $stmt->fetch();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verifica si un cómic ya fue descargado completamente (BD + disco).
     * Soporta auto-reparación de estado 'error' con archivos en disco,
     * reanudación de descargas parciales, y detección de duplicados en disco.
     *
     * @param int $id ID del cómic
     * @param string $titulo Título del cómic (para verificación en disco)
     * @param callable $scanImagesFn Función para escanear imágenes (escanear_imagenes)
     * @param callable $progressFn Función para enviar progreso
     * @return array{duplicate: bool, resume: bool, existing_data: ?array, start_page: int}
     */
    public function checkDuplicate(
        int $id,
        string $titulo,
        callable $scanImagesFn,
        callable $progressFn
    ): array {
        $result = [
            'duplicate'     => false,
            'resume'        => false,
            'existing_data' => null,
            'start_page'    => 1,
        ];

        $existing = $this->findById($id);

        if ($existing) {
            $result['existing_data'] = $existing;

            // Ya está completo en BD
            if ($existing['estado'] === 'completo') {
                // Verificar si también existe en disco
                if ($existing['ruta_carpeta'] && is_dir($existing['ruta_carpeta'])) {
                    $files = $scanImagesFn($existing['ruta_carpeta']);
                    if (count($files) >= ($existing['total_paginas'] * 0.8)) {
                        $progressFn([
                            'type'    => 'error',
                            'message' => "⚠️ El cómic ID {$id} («{$existing['titulo']}») YA EXISTE y está completo. Descarga omitida."
                        ]);
                        $result['duplicate'] = true;
                        return $result;
                    }
                }
            }

            // Estado parcial o error → auto-reparar si hay archivos
            if ($existing['estado'] === 'error' && $existing['ruta_carpeta'] && is_dir($existing['ruta_carpeta'])) {
                $files = $scanImagesFn($existing['ruta_carpeta']);
                $numFiles = count($files);
                if ($numFiles > 0) {
                    $totalEsperado = (int) $existing['total_paginas'];
                    $nuevoEstado = ($numFiles >= $totalEsperado && $totalEsperado > 0) ? 'completo' : 'parcial';

                    $this->updateStatus($id, $nuevoEstado, $numFiles, max(0, $totalEsperado - $numFiles));

                    $progressFn([
                        'type'    => 'info',
                        'message' => "🔧 Cómic ID {$id} corregido: estado '{$existing['estado']}' → '{$nuevoEstado}' ({$numFiles}/{$totalEsperado} páginas en disco)"
                    ]);

                    if ($nuevoEstado === 'completo') {
                        $result['duplicate'] = true;
                        return $result;
                    }
                }
            }

            // Estado 'descargando' o 'parcial' → reanudar
            if (in_array($existing['estado'], ['descargando', 'parcial'], true)) {
                $result['resume'] = true;
                $result['start_page'] = ($existing['paginas_ok'] ?? 0) + 1;

                $this->setDownloading($id);

                $progressFn([
                    'type'    => 'info',
                    'message' => "🔄 Reanudando descarga del cómic ID {$id} desde página {$result['start_page']} (estado: {$existing['estado']})"
                ]);
                return $result;
            }

            // Existe en BD pero no en disco
            $progressFn([
                'type'    => 'info',
                'message' => "🔄 Cómic ID {$id} registrado en BD pero no encontrado en disco. Re-descargando..."
            ]);
            return $result;
        }

        // Verificar en disco (caso de BD borrada o migración)
        $sanitized = preg_replace('#[/:*?"<>|]#', '_', $titulo);
        $sanitized = substr($sanitized, 0, 200);
        $dirName = "[{$id}] {$sanitized}";
        $candidateDir = DOWNLOADS_DIR . '/' . $dirName;

        if (is_dir($candidateDir)) {
            $files = $scanImagesFn($candidateDir);
            if (count($files) > 0) {
                $progressFn([
                    'type'    => 'info',
                    'message' => "📂 Cómic ID {$id} encontrado en disco pero no en BD. Registrando..."
                ]);
                // No es duplicado, permitimos continuar
            }
        }

        return $result;
    }

    /**
     * Registra o actualiza un cómic en la BD (UPSERT).
     *
     * @param int $id ID del cómic
     * @param string $titulo Título
     * @param string|null $universo Universo
     * @param string|null $autor Autor
     * @param string|null $artista Artista
     * @param string|null $tags Tags
     * @param string|null $sinopsis Sinopsis
     * @param string|null $idioma Idioma
     * @param float|null $rating Rating
     * @param int $totalPaginas Total de páginas
     * @param int $paginasOk Páginas descargadas correctamente
     * @param int $paginasFail Páginas con error
     * @param string $estado Estado (completo, parcial, error)
     * @param string $rutaCarpeta Ruta del directorio
     * @param string|null $taxonomias JSON de taxonomías procesadas
     * @param callable $calculateSizeFn Función para calcular tamaño de directorio
     */
    public function save(
        int $id,
        string $titulo,
        ?string $universo,
        ?string $autor,
        ?string $artista,
        ?string $tags,
        ?string $sinopsis,
        ?string $idioma,
        ?float $rating,
        int $totalPaginas,
        int $paginasOk,
        int $paginasFail,
        string $estado,
        string $rutaCarpeta,
        ?string $taxonomias = null,
        callable $calculateSizeFn = null
    ): void {
        $tamano = 0;
        if ($calculateSizeFn !== null && $rutaCarpeta && is_dir($rutaCarpeta)) {
            $tamano = $calculateSizeFn($rutaCarpeta);
        } elseif ($rutaCarpeta && is_dir($rutaCarpeta)) {
            $tamano = $this->calculateDirSize($rutaCarpeta);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO comics_descargados
             (id_fuente, titulo, universo, autor, artista, tags, sinopsis,
              total_paginas, paginas_ok, paginas_fail, tamano_bytes, idioma, rating,
              estado, ruta_carpeta, taxonomias)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             titulo = VALUES(titulo),
             universo = COALESCE(VALUES(universo), universo),
             autor = COALESCE(VALUES(autor), autor),
             artista = COALESCE(VALUES(artista), artista),
             tags = COALESCE(VALUES(tags), tags),
             sinopsis = COALESCE(VALUES(sinopsis), sinopsis),
             total_paginas = VALUES(total_paginas),
             paginas_ok = VALUES(paginas_ok),
             paginas_fail = VALUES(paginas_fail),
             tamano_bytes = VALUES(tamano_bytes),
             idioma = COALESCE(VALUES(idioma), idioma),
             rating = COALESCE(VALUES(rating), rating),
             estado = VALUES(estado),
             ruta_carpeta = VALUES(ruta_carpeta),
             taxonomias = COALESCE(VALUES(taxonomias), taxonomias)'
        );
        $stmt->execute([
            $id, $titulo, $universo, $autor, $artista, $tags, $sinopsis,
            $totalPaginas, $paginasOk, $paginasFail, $tamano, $idioma, $rating,
            $estado, $rutaCarpeta, $taxonomias
        ]);
    }

    /**
     * Actualiza el estado a 'descargando'.
     */
    public function setDownloading(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE comics_descargados SET estado = 'descargando' WHERE id_fuente = ?"
        );
        $stmt->execute([$id]);
    }

    /**
     * Actualiza el estado y contadores de un cómic.
     */
    public function updateStatus(int $id, string $estado, int $paginasOk, int $paginasFail): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE comics_descargados SET estado = ?, paginas_ok = ?, paginas_fail = ? WHERE id_fuente = ?'
        );
        $stmt->execute([$estado, $paginasOk, $paginasFail, $id]);
    }

    /**
     * Elimina registros de cómics incompletos (parcial/error).
     */
    public function deleteIncomplete(int $id): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "DELETE FROM comics_descargados WHERE id_fuente = ? AND estado != ?"
            );
            $stmt->execute([$id, 'completo']);
        } catch (\Exception $e) {
            // Ignorar errores de BD en cleanup
        }
    }

    // ──────────────────────────────────────────────────────────
    //  BATCH HISTORIAL
    // ──────────────────────────────────────────────────────────

    /**
     * Obtiene la última página procesada para una URL de batch.
     */
    public function getBatchLastPage(string $url): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT ultima_pagina FROM batch_historial WHERE url_base = ?'
            );
            $stmt->execute([$url]);
            $row = $stmt->fetch();
            if ($row) {
                return (int) $row['ultima_pagina'];
            }
        } catch (\Exception $e) {
            // Ignorar
        }
        return 0;
    }

    /**
     * Guarda o actualiza el historial de procesamiento de una URL de batch.
     */
    public function saveBatchHistory(
        string $url,
        string $universo,
        int $paginaInicial,
        int $ultimaPagina,
        int $maxComics,
        int $totalEnlaces,
        int $comicsDescargados,
        int $comicsOmitidos,
        int $comicsErrores,
        bool $completado
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO batch_historial
                 (url_base, universo, ultima_pagina, pagina_inicial, max_comics,
                  total_enlaces, comics_descargados, comics_omitidos, comics_errores,
                  completado, fecha_ejecucion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                 universo = VALUES(universo),
                 ultima_pagina = VALUES(ultima_pagina),
                 pagina_inicial = VALUES(pagina_inicial),
                 max_comics = VALUES(max_comics),
                 total_enlaces = VALUES(total_enlaces),
                 comics_descargados = comics_descargados + VALUES(comics_descargados),
                 comics_omitidos = comics_omitidos + VALUES(comics_omitidos),
                 comics_errores = comics_errores + VALUES(comics_errores),
                 completado = VALUES(completado),
                 fecha_ejecucion = NOW()'
            );
            $stmt->execute([
                $url, $universo, $ultimaPagina, $paginaInicial, $maxComics,
                $totalEnlaces, $comicsDescargados, $comicsOmitidos, $comicsErrores,
                $completado ? 1 : 0
            ]);
        } catch (\Exception $e) {
            // Si falla el historial, no interrumpimos
        }
    }

    /**
     * Actualiza solo la última página en el historial.
     */
    public function updateBatchLastPage(string $url, int $pagina): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE batch_historial SET ultima_pagina = ?, fecha_ejecucion = NOW() WHERE url_base = ?'
            );
            $stmt->execute([$pagina, $url]);
        } catch (\Exception $e) {
            // Ignorar
        }
    }

    // ──────────────────────────────────────────────────────────
    //  BATCH PROGRESO
    // ──────────────────────────────────────────────────────────

    /**
     * Inicializa o actualiza el progreso batch.
     */
    public function initBatchProgress(string $universo, string $url, int $startPage, int $maxComics): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO batch_progreso (universo, url_base, pagina_actual, max_comics, en_progreso, fecha_inicio)
                 VALUES (?, ?, ?, ?, TRUE, NOW())
                 ON DUPLICATE KEY UPDATE
                 url_base = VALUES(url_base),
                 max_comics = VALUES(max_comics),
                 en_progreso = TRUE,
                 fecha_inicio = COALESCE(fecha_inicio, NOW()),
                 fecha_fin = NULL'
            );
            $stmt->execute([$universo, $url, $startPage, $maxComics]);
        } catch (\Exception $e) {
            // Si falla, se registra externamente
        }
    }

    /**
     * Actualiza página actual y contador de obtenidos en batch_progreso.
     */
    public function updateBatchProgress(string $universo, int $currentPage, int $totalObtenidos): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE batch_progreso SET pagina_actual = ?, comics_obtenidos = ? WHERE universo = ?'
            );
            $stmt->execute([$currentPage, $totalObtenidos, $universo]);
        } catch (\Exception $e) {
            // Ignorar
        }
    }

    /**
     * Incrementa contador de omitidos en batch_progreso.
     */
    public function incrementBatchOmitted(string $universo): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE batch_progreso SET comics_omitidos = comics_omitidos + 1 WHERE universo = ?'
            );
            $stmt->execute([$universo]);
        } catch (\Exception $e) {
            // Ignorar
        }
    }

    /**
     * Incrementa contador de descargados y errores en batch_progreso.
     */
    public function incrementBatchDownloaded(string $universo, bool $isError): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE batch_progreso SET
                 comics_descargados = comics_descargados + 1,
                 comics_errores = comics_errores + ?
                 WHERE universo = ?'
            );
            $stmt->execute([$isError ? 1 : 0, $universo]);
        } catch (\Exception $e) {
            // Ignorar
        }
    }

    /**
     * Finaliza el progreso batch (en_progreso = FALSE, fecha_fin = NOW()).
     */
    public function finishBatchProgress(string $universo): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE batch_progreso SET en_progreso = FALSE, fecha_fin = NOW() WHERE universo = ?'
            );
            $stmt->execute([$universo]);
        } catch (\Exception $e) {
            // Ignorar
        }
    }

    // ──────────────────────────────────────────────────────────
    //  UTILIDADES
    // ──────────────────────────────────────────────────────────

    /**
     * Calcula el tamaño total de un directorio en bytes.
     */
    private function calculateDirSize(string $dir): int
    {
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $size += $file->getSize();
        }
        return $size;
    }
}
