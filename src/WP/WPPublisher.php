<?php
/**
 * WPPublisher.php
 *
 * ORQUESTADOR PRINCIPAL del módulo de publicación automática a WordPress.
 *
 * Flujo de trabajo por cada manga/cómic:
 *
 *   PASO A: Subida de Imágenes (Páginas del Manga)
 *     - Itera sobre las imágenes .webp en el directorio del cómic
 *     - Hace POST individual a /wp/v2/media
 *     - Almacena los IDs numéricos retornados
 *
 *   PASO B: Formateo del Campo de Galería
 *     - Los IDs se convierten a un string separado por comas
 *     - Ej: "1024,1025,1026" para el plugin photo_gallery
 *
 *   PASO C: Publicación del Cómic
 *     - Sincroniza taxonomías (obtiene IDs numéricos de WordPress)
 *     - Construye el payload con acf.image_comic
 *     - POST a /wp/v2/posts
 *
 * @package ScrapApp
 * @subpackage WP
 */

class WPPublisher
{
    /** @var WPClient Cliente HTTP para WordPress REST API */
    private WPClient $client;

    /** @var WPTaxonomySync Sincronizador de taxonomías */
    private WPTaxonomySync $taxonomySync;

    /** @var PDO Conexión a la BD local */
    private PDO $pdo;

    /** @var array<string, mixed> Estadísticas acumuladas */
    private array $stats;

    /** @var bool Si es true, omite subir imágenes (solo publica) */
    private bool $skipImageUpload;

    /**
     * @param WPClient        $client
     * @param WPTaxonomySync  $taxonomySync
     * @param PDO             $pdo
     * @param array<string, mixed> $options
     */
    public function __construct(
        WPClient $client,
        WPTaxonomySync $taxonomySync,
        PDO $pdo,
        array $options = []
    ) {
        $this->client = $client;
        $this->taxonomySync = $taxonomySync;
        $this->pdo = $pdo;
        $this->skipImageUpload = (bool) ($options['skip_image_upload'] ?? false);
        $this->stats = [
            'total_comics'     => 0,
            'published'        => 0,
            'errors'           => 0,
            'skipped'          => 0,
            'images_uploaded'  => 0,
            'images_failed'    => 0,
            'details'          => [],
        ];
    }

    /**
     * Publica un cómic individual en WordPress.
     *
     * @param int $comicId ID del cómic en la BD local (id_fuente)
     * @return array<string, mixed> Resultado de la operación
     */
    public function publishComic(int $comicId): array
    {
        $this->stats['total_comics']++;

        // ── 1. Cargar datos del cómic desde la BD local ──
        $comic = $this->loadComicData($comicId);
        if ($comic === null) {
            $this->stats['errors']++;
            return [
                'success' => false,
                'comic_id' => $comicId,
                'error'   => "Cómic ID {$comicId} no encontrado en la base de datos",
            ];
        }

        // ── 2. Verificar si ya fue publicado ──
        if (!empty($comic['wp_post_id'])) {
            $this->stats['skipped']++;
            return [
                'success' => true,
                'comic_id' => $comicId,
                'wp_post_id' => (int) $comic['wp_post_id'],
                'skipped' => true,
                'message' => "Cómic ya publicado (WP Post ID: {$comic['wp_post_id']})",
            ];
        }

        $titulo      = $comic['titulo'];
        $rutaCarpeta = $comic['ruta_carpeta'];
        $taxData     = $comic['taxonomias_parsed'];

        $this->log("Iniciando publicación de «{$titulo}» (ID {$comicId})");

        // ── 3. PASO A: Subir imágenes ──
        $mediaIds = [];
        if (!$this->skipImageUpload && $rutaCarpeta && is_dir($rutaCarpeta)) {
            $this->log(" PASO A: Subiendo imágenes desde {$rutaCarpeta}");
            $mediaIds = $this->uploadComicImages($comicId, $titulo, $rutaCarpeta);
            if (empty($mediaIds)) {
                $this->stats['errors']++;
                $this->updatePublishStatus($comicId, 'error', null, 'No se pudo subir ninguna imagen');
                return [
                    'success' => false,
                    'comic_id' => $comicId,
                    'error'   => "No se pudo subir ninguna imagen para «{$titulo}»",
                ];
            }
            $this->log("   → {$comicId}: " . count($mediaIds) . " imágenes subidas exitosamente");
        } elseif ($this->skipImageUpload) {
            $this->log("   → Subida de imágenes omitida (modo skip_image_upload)");
        } else {
            $this->log("   → Sin imágenes para subir (directorio no encontrado: {$rutaCarpeta})");
        }

        // ── 4. PASO B: Formatear galería como string separado por comas ──
        $imageComicString = !empty($mediaIds) ? implode(',', $mediaIds) : '';
        $this->log(" PASO B: Galería formateada: " . ($imageComicString ?: 'vacía'));

        // ── 5. Sincronizar taxonomías ──
        $this->log(" PASO C: Sincronizando taxonomías con WordPress...");
        $taxonomySyncStart = microtime(true);

        $syncedIds = $this->taxonomySync->syncAll($taxData);
        $taxPayload = $this->taxonomySync->buildTaxonomyPayload($syncedIds);

        $this->log("   → Taxonomías sincronizadas en " . round(microtime(true) - $taxonomySyncStart, 2) . "s");
        $this->log("   → IDs: " . json_encode($syncedIds, JSON_UNESCAPED_UNICODE));

        // ── 6. Determinar portada (primera página del cómic) ──
        // Las imágenes se suben en orden inverso (última página primero),
        // por lo que la primera página (001.webp) es el último elemento del array.
        $featuredMediaId = !empty($mediaIds) ? (int) end($mediaIds) : 0;
        if ($featuredMediaId > 0) {
            $this->log("   → Portada: attachment ID {$featuredMediaId} (primera página del cómic)");
        }

        // ── 7. Construir payload del post ──
        $payload = $this->buildPostPayload($titulo, $imageComicString, $taxPayload, $featuredMediaId);

        $this->log(" PASO C: Publicando post en WordPress...");
        $this->log("   → Payload: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // ── 7. Publicar ──
        try {
            $response = $this->client->createPost($payload);
            $wpPostId = (int) ($response['id'] ?? 0);

            if ($wpPostId > 0) {
                // ── 8. Actualizar meta del campo image_comic (segundo paso) ──
                // El plugin ACF Photo Gallery Field (navz.me) guarda los datos
                // a través de $_POST['acf-photo-gallery-groups'] en su hook save_post.
                // Cuando se crea un post vía REST API, ese $_POST no existe,
                // por lo que debemos establecer el meta manualmente.
                if ($imageComicString !== '') {
                    try {
                        $metaResult = $this->client->updatePostMeta($wpPostId, [
                            'image_comic'  => $imageComicString,
                            '_image_comic' => 'field_69e4761779913',
                        ]);
                        $this->log("   → Meta image_comic actualizado: {$imageComicString}");
                    } catch (RuntimeException $e) {
                        // No fatal: el post se creó, la meta se puede corregir manualmente
                        $this->log("   ⚠️ No se pudo actualizar meta image_comic: " . $e->getMessage());
                    }
                }

                $this->stats['published']++;
                $this->updatePublishStatus($comicId, 'published', $wpPostId);
                $this->log("   ✅ Post creado exitosamente: WP ID {$wpPostId}");

                return [
                    'success'     => true,
                    'comic_id'    => $comicId,
                    'wp_post_id'  => $wpPostId,
                    'titulo'      => $titulo,
                    'images_uploaded' => count($mediaIds),
                    'media_ids'   => $mediaIds,
                    'taxonomies'  => $syncedIds,
                ];
            }

            $this->stats['errors']++;
            $this->updatePublishStatus($comicId, 'error', null, 'Respuesta sin ID de post');
            return [
                'success' => false,
                'comic_id' => $comicId,
                'error'   => "Respuesta inesperada de WordPress: sin ID de post",
            ];
        } catch (RuntimeException $e) {
            $this->stats['errors']++;
            $this->updatePublishStatus($comicId, 'error', null, $e->getMessage());
            $this->log("   ❌ Error publicando «{$titulo}»: " . $e->getMessage());

            return [
                'success' => false,
                'comic_id' => $comicId,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Publica múltiples cómics en lote.
     *
     * @param array<int> $comicIds Lista de IDs de cómics a publicar
     * @param callable|null $progressCallback Función de callback para reportar progreso
     *        fn(array $result) => void
     * @return array<string, mixed> Resultados agregados
     */
    public function publishBatch(array $comicIds, ?callable $progressCallback = null): array
    {
        $this->stats = [
            'total_comics'    => count($comicIds),
            'published'       => 0,
            'errors'          => 0,
            'skipped'         => 0,
            'images_uploaded' => 0,
            'images_failed'   => 0,
            'details'         => [],
        ];

        // Limpiar cache de taxonomías al empezar el lote
        $this->taxonomySync->clearCache();

        // Limpiar señal de stop residual de ejecuciones anteriores
        if (defined('PUBLISH_STOP_FILE') && file_exists(PUBLISH_STOP_FILE)) {
            @unlink(PUBLISH_STOP_FILE);
        }

        foreach ($comicIds as $index => $comicId) {
            // Verificar señal de detención antes de cada cómic
            if ($this->isStopRequested()) {
                $this->log("⏹ Señal de detención detectada. Cancelando lote después de {$index} cómics.");
                break;
            }

            $result = $this->publishComic((int) $comicId);
            $this->stats['details'][] = $result;

            if (!empty($result['images_uploaded'])) {
                $this->stats['images_uploaded'] += $result['images_uploaded'];
            }

            if ($progressCallback !== null) {
                $progressCallback([
                    'index'      => $index + 1,
                    'total'      => count($comicIds),
                    'comic_id'   => $comicId,
                    'result'     => $result,
                    'stats'      => $this->getStats(),
                ]);
            }
        }

        // Limpiar señal de stop al terminar
        if (defined('PUBLISH_STOP_FILE') && file_exists(PUBLISH_STOP_FILE)) {
            @unlink(PUBLISH_STOP_FILE);
        }

        return $this->getStats();
    }

    /**
     * Publica todos los cómics que aún no han sido publicados.
     *
     * @param int|null $limit Límite de cómics a publicar (null = todos)
     * @param callable|null $progressCallback
     * @return array<string, mixed>
     */
    public function publishPending(?int $limit = null, ?callable $progressCallback = null): array
    {
        $comicIds = $this->getPendingComicIds($limit);

        if (empty($comicIds)) {
            return [
                'total_comics' => 0,
                'published'    => 0,
                'errors'       => 0,
                'skipped'      => 0,
                'message'      => 'No hay cómics pendientes por publicar',
                'details'      => [],
            ];
        }

        return $this->publishBatch($comicIds, $progressCallback);
    }

    /**
     * Retorna las estadísticas actuales de la sesión de publicación.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $clientStats = $this->client->getStats();
        return array_merge($this->stats, [
            'api_requests' => $clientStats['requests'] ?? 0,
            'api_success'  => $clientStats['success'] ?? 0,
            'api_errors'   => $clientStats['errors'] ?? 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  MÉTODOS PRIVADOS
    // ─────────────────────────────────────────────────────────────

    /**
     * Carga los datos de un cómic desde la BD local.
     *
     * @param int $comicId
     * @return array<string, mixed>|null
     */
    private function loadComicData(int $comicId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id_fuente, titulo, universo, autor, artista, tags,
                        taxonomias, sinopsis, total_paginas, ruta_carpeta,
                        wp_post_id, wp_publish_status
                 FROM comics_descargados
                 WHERE id_fuente = ?"
            );
            $stmt->execute([$comicId]);
            $comic = $stmt->fetch();

            if (!$comic) {
                return null;
            }

            // Parsear taxonomías
            $comic['taxonomias_parsed'] = [];
            if (!empty($comic['taxonomias']) && is_string($comic['taxonomias'])) {
                $parsed = json_decode($comic['taxonomias'], true);
                if (is_array($parsed)) {
                    $comic['taxonomias_parsed'] = $parsed;
                }
            }

            return $comic;
        } catch (Exception $e) {
            $this->log("Error cargando datos del cómic {$comicId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sube todas las imágenes de un cómic a WordPress.
     *
     * @param int    $comicId
     * @param string $titulo
     * @param string $rutaCarpeta
     * @return array<int> IDs de los attachments creados en WordPress
     */
    private function uploadComicImages(int $comicId, string $titulo, string $rutaCarpeta): array
    {
        $mediaIds = [];

        // Escanear imágenes .webp en el directorio del cómic
        $images = $this->scanWebpImages($rutaCarpeta);

        if (empty($images)) {
            $this->log("   ℹ️  No se encontraron imágenes .webp en {$rutaCarpeta}");
            return $mediaIds;
        }

        // Invertir orden: la última página se sube primero para que al
        // publicarse en WordPress queden en orden ascendente (página 1 primero)
        $images = array_reverse($images);

        // Sanitizar título para usarlo como nombre base
        $titleSlug = $this->sanitizeTitleSlug($titulo);

        foreach ($images as $index => $imagePath) {
            $pageNum = $index + 1;
            $fileName = "{$comicId}-{$titleSlug}-pagina-{$pageNum}.webp";
            $altText = "{$titulo} - Página {$pageNum}";

            try {
                $response = $this->client->uploadImage($imagePath, $fileName, $altText);
                if (isset($response['id'])) {
                    $mediaIds[] = (int) $response['id'];
                }
            } catch (RuntimeException $e) {
                $this->stats['images_failed']++;
                $this->log("   ⚠️  Error subiendo imagen {$pageNum}: " . $e->getMessage());
                // Continuar con la siguiente imagen
            }
        }

        return $mediaIds;
    }

    /**
     * Escanea un directorio y retorna solo imágenes .webp ordenadas.
     *
     * @param string $dir
     * @return array<string> Rutas completas de archivos .webp
     */
    private function scanWebpImages(string $dir): array
    {
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
            if (is_file($path) && strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'webp') {
                $files[] = $path;
            }
        }
        closedir($handle);

        // Orden natural (001.webp, 002.webp, ...)
        natsort($files);
        return array_values($files);
    }

    /**
     * Construye el payload completo para el POST a /wp/v2/posts.
     *
     * @param string $titulo
     * @param string $imageComicString IDs separados por coma para acf.photo_gallery.image_comic
     * @param array<string, mixed> $taxPayload IDs de taxonomías
     * @return array<string, mixed>
     */
    private function buildPostPayload(string $titulo, string $imageComicString, array $taxPayload, int $featuredMediaId = 0): array
    {
        $payload = [
            'title'  => $titulo,
            'status' => 'publish',
            'acf'    => [],
        ];

        // ── Portada / Featured Image (primera imagen del cómic) ──
        if ($featuredMediaId > 0) {
            $payload['featured_media'] = $featuredMediaId;
        }

        // ── Campo ACF: image_comic (string separado por comas de IDs de attachment) ──
        // El campo image_comic es de tipo 'photo_gallery' (plugin navz.me).
        // En la REST API de ACF se envía como acf.image_comic (NO anidado bajo photo_gallery).
        // El plugin guarda en wp_postmeta como: image_comic = "id1,id2,id3,..."
        if ($imageComicString !== '') {
            $payload['acf']['image_comic'] = $imageComicString;
        }

        // ── Taxonomías ──
        // NOTA: WordPress REST API espera 'tags' (no 'post_tag') para la taxonomía post_tag
        if (isset($taxPayload['tags']) && !empty($taxPayload['tags'])) {
            $payload['tags'] = $taxPayload['tags'];
        }

        if (isset($taxPayload['universo']) && !empty($taxPayload['universo'])) {
            $payload['universo'] = $taxPayload['universo'];
        }

        if (isset($taxPayload['personaje']) && !empty($taxPayload['personaje'])) {
            $payload['personaje'] = $taxPayload['personaje'];
        }

        if (isset($taxPayload['idioma'])) {
            $payload['idioma'] = $taxPayload['idioma'];
        }

        if (isset($taxPayload['tipo']) && !empty($taxPayload['tipo'])) {
            $payload['tipo'] = $taxPayload['tipo'];
        }

        return $payload;
    }

    /**
     * Actualiza el estado de publicación en la BD local.
     *
     * @param int         $comicId
     * @param string      $status  'pending' | 'publishing' | 'published' | 'error'
     * @param int|null    $wpPostId ID del post en WordPress
     * @param string|null $errorMsg Mensaje de error si lo hay
     */
    private function updatePublishStatus(int $comicId, string $status, ?int $wpPostId = null, ?string $errorMsg = null): void
    {
        try {
            // Verificar si la columna wp_publish_status existe
            // Si no, la creamos automáticamente
            $this->ensurePublishColumns();

            if ($wpPostId !== null) {
                $stmt = $this->pdo->prepare(
                    "UPDATE comics_descargados
                     SET wp_publish_status = ?,
                         wp_post_id = ?,
                         wp_publish_error = ?
                     WHERE id_fuente = ?"
                );
                $stmt->execute([$status, $wpPostId, $errorMsg, $comicId]);
            } else {
                $stmt = $this->pdo->prepare(
                    "UPDATE comics_descargados
                     SET wp_publish_status = ?,
                         wp_publish_error = ?
                     WHERE id_fuente = ?"
                );
                $stmt->execute([$status, $errorMsg, $comicId]);
            }
        } catch (Exception $e) {
            $this->log("Error actualizando estado de publicación: " . $e->getMessage());
        }
    }

    /**
     * Asegura que las columnas de publicación existan en la tabla.
     */
    private function ensurePublishColumns(): void
    {
        try {
            // Verificar si la columna wp_publish_status existe
            $stmt = $this->pdo->query("SHOW COLUMNS FROM comics_descargados LIKE 'wp_publish_status'");
            if (!$stmt->fetch()) {
                $this->pdo->exec(
                    "ALTER TABLE comics_descargados
                     ADD COLUMN wp_publish_status VARCHAR(20) DEFAULT NULL
                     COMMENT 'Estado de publicación en WordPress (pending|publishing|published|error)'
                     AFTER ruta_carpeta,
                     ADD COLUMN wp_post_id INT DEFAULT NULL
                     COMMENT 'ID del post en WordPress'
                     AFTER wp_publish_status,
                     ADD COLUMN wp_publish_error TEXT DEFAULT NULL
                     COMMENT 'Mensaje de error de publicación en WordPress'
                     AFTER wp_post_id,
                     ADD INDEX idx_wp_publish_status (wp_publish_status),
                     ADD INDEX idx_wp_post_id (wp_post_id)"
                );
                $this->log("✅ Columnas de publicación añadidas a comics_descargados");
            }
        } catch (Exception $e) {
            // Si falla, asumir que ya existen o ignorar
            $this->log("Aviso: no se pudieron verificar/crear columnas de publicación: " . $e->getMessage());
        }
    }

    /**
     * Obtiene los IDs de cómics pendientes de publicación.
     *
     * @param int|null $limit
     * @return array<int>
     */
    private function getPendingComicIds(?int $limit = null): array
    {
        try {
            $this->ensurePublishColumns();

            $sql = "SELECT id_fuente
                    FROM comics_descargados
                    WHERE (wp_publish_status IS NULL OR wp_publish_status IN ('pending', 'error'))
                      AND estado = 'completo'
                    ORDER BY id_fuente ASC";

            if ($limit !== null) {
                $sql .= " LIMIT " . (int) $limit;
            }

            $stmt = $this->pdo->query($sql);
            $ids = [];
            while ($row = $stmt->fetch()) {
                $ids[] = (int) $row['id_fuente'];
            }
            return $ids;
        } catch (Exception $e) {
            $this->log("Error obteniendo cómics pendientes: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Sanitiza un título para usarlo como parte del nombre de archivo.
     *
     * @param string $title
     * @return string
     */
    private function sanitizeTitleSlug(string $title): string
    {
        $slug = mb_strtolower(trim($title), 'UTF-8');
        $slug = preg_replace('/[^a-z0-9áéíóúüñ\s]/u', '', $slug);
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 60);
        return $slug ?: 'comic';
    }

    // ─────────────────────────────────────────────────────────────
    //  LOGGING
    // ─────────────────────────────────────────────────────────────

    /**
     * Registra un mensaje en el log de publicación.
     *
     * @param string $message
     */
    private function log(string $message): void
    {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/wp_publisher.log';
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Verifica si se ha solicitado la detención del proceso.
     *
     * @return bool
     */
    private function isStopRequested(): bool
    {
        return defined('PUBLISH_STOP_FILE') && file_exists(PUBLISH_STOP_FILE);
    }
}
