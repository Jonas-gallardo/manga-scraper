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

    /** @var array<string, mixed> Estado actual del progreso (para polling UI) */
    private array $currentProgress;

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
        $this->currentProgress = [
            'status'          => 'idle',
            'current_comic'   => null,
            'stats'           => $this->stats,
            'log'             => [],
            'timestamp'       => time(),
        ];
    }

    /**
     * Inicializa el archivo de progreso para polling desde la UI.
     */
    private function initProgressFile(string $status = 'publishing'): void
    {
        $this->currentProgress = [
            'status'        => $status,
            'current_comic' => null,
            'stats'         => $this->stats,
            'log'           => [],
            'timestamp'     => time(),
        ];
        $this->writeProgressFile();
    }

    /**
     * Escribe el estado actual al archivo de progreso (para polling JS).
     */
    private function writeProgressFile(): void
    {
        $this->currentProgress['timestamp'] = time();
        $this->currentProgress['stats'] = $this->getStats();
        $json = json_encode($this->currentProgress, JSON_UNESCAPED_UNICODE);
        if (defined('PUBLISH_PROGRESS_FILE')) {
            @file_put_contents(PUBLISH_PROGRESS_FILE, $json, LOCK_EX);
        }
    }

    /**
     * Añade un mensaje al log del progreso actual.
     */
    private function addProgressLog(string $message, string $type = 'info'): void
    {
        $this->currentProgress['log'][] = [
            'time' => date('H:i:s'),
            'msg'  => $message,
            'type' => $type,
        ];
        // Mantener solo últimos 200 mensajes
        if (count($this->currentProgress['log']) > 200) {
            array_shift($this->currentProgress['log']);
        }
        $this->writeProgressFile();
        // También al archivo de log tradicional
        $this->log("[{$type}] {$message}");
    }

    /**
     * Actualiza el cómic actual en el progreso.
     */
    private function setCurrentComic(int $comicId, string $titulo, int $totalPages = 0): void
    {
        $this->currentProgress['current_comic'] = [
            'id'             => $comicId,
            'title'          => $titulo,
            'total_pages'    => $totalPages,
            'uploaded_pages' => 0,
            'current_page'   => 0,
        ];
        $this->writeProgressFile();
    }

    /**
     * Actualiza el progreso de páginas del cómic actual.
     */
    private function updateCurrentPageProgress(int $pageNum, int $totalPages): void
    {
        if ($this->currentProgress['current_comic'] !== null) {
            $this->currentProgress['current_comic']['uploaded_pages'] = $pageNum;
            $this->currentProgress['current_comic']['current_page'] = $pageNum;
            $this->currentProgress['current_comic']['total_pages'] = $totalPages;
            $this->writeProgressFile();
        }
    }

    /**
     * Marca el progreso como completado exitosamente.
     */
    private function markProgressSuccess(string $message = 'Completado'): void
    {
        $this->currentProgress['status'] = 'completed';
        $this->addProgressLog("✅ {$message}", 'success');
        $this->writeProgressFile();
        $this->cleanProgressFileAfterDelay();
    }

    /**
     * Marca el progreso como detenido.
     */
    private function markProgressStopped(string $message = 'Detenido por el usuario'): void
    {
        $this->currentProgress['status'] = 'stopped';
        $this->addProgressLog("⏹ {$message}", 'warning');
        $this->writeProgressFile();
        $this->cleanProgressFileAfterDelay();
    }

    /**
     * Marca el progreso con error.
     */
    private function markProgressError(string $message): void
    {
        $this->currentProgress['status'] = 'error';
        $this->addProgressLog("❌ {$message}", 'error');
        $this->writeProgressFile();
        $this->cleanProgressFileAfterDelay();
    }

    /**
     * Limpia el archivo de progreso después de un breve retardo
     * para que la UI tenga tiempo de leer el estado final.
     *
     * La UI (JavaScript) espera 5s antes de llamar a clearProgressSilent(),
     * pero si el usuario cierra/recarga antes, el archivo queda huérfano.
     * Este método programa una auto-limpieza PHP via shutdown.
     */
    private function cleanProgressFileAfterDelay(): void
    {
        // Marcar el timestamp de finalización para que handleProgress()
        // pueda detectar archivos stale tras 30s de inactividad.
        $this->currentProgress['ended'] = date('c');
        $this->currentProgress['_finished_at'] = time();
        $this->writeProgressFile();
    }

    /**
     * Publica un cómic individual en WordPress.
     *
     * @param int $comicId ID del cómic en la BD local (id_fuente)
     * @param callable|null $pageProgressCallback fn(int $pageNum, int $totalPages, string $titulo) => void
     * @return array<string, mixed> Resultado de la operación
     */
    public function publishComic(int $comicId, ?callable $pageProgressCallback = null): array
    {
        $this->stats['total_comics']++;

        // ── 1. Cargar datos del cómic desde la BD local ──
        $comic = $this->loadComicData($comicId);
        if ($comic === null) {
            $this->stats['errors']++;
            $this->addProgressLog("❌ Cómic ID {$comicId} no encontrado en BD", 'error');
            return [
                'success' => false,
                'comic_id' => $comicId,
                'error'   => "Cómic ID {$comicId} no encontrado en la base de datos",
            ];
        }

        $titulo      = $comic['titulo'];
        $rutaCarpeta = $comic['ruta_carpeta'];
        $taxData     = $comic['taxonomias_parsed'];
        $totalPaginas = (int) ($comic['total_paginas'] ?? 0);

        // ── 2. Verificar si ya fue publicado ──
        if (!empty($comic['wp_post_id'])) {
            $this->stats['skipped']++;
            $this->addProgressLog("⏭ «{$titulo}» ya publicado (WP Post ID: {$comic['wp_post_id']})", 'warning');
            return [
                'success' => true,
                'comic_id' => $comicId,
                'wp_post_id' => (int) $comic['wp_post_id'],
                'skipped' => true,
                'message' => "Cómic ya publicado (WP Post ID: {$comic['wp_post_id']})",
            ];
        }

        // ── 3. Marcar como "publicando" en BD ──
        $this->updatePublishStatus($comicId, 'publishing');
        $this->setCurrentComic($comicId, $titulo, $totalPaginas);
        $this->addProgressLog("▶️ Publicando «{$titulo}» (ID {$comicId})...", 'info');

        // ── 4. PASO A: Subir imágenes ──
        $mediaIds = [];
        if (!$this->skipImageUpload && $rutaCarpeta && is_dir($rutaCarpeta)) {
            // Escanear imágenes para saber total real
            $allImages = $this->scanWebpImages($rutaCarpeta);
            $realTotal = count($allImages);
            $this->setCurrentComic($comicId, $titulo, $realTotal);
            $this->addProgressLog("📤 PASO A: Subiendo {$realTotal} imágenes...", 'info');

            $uploadResult = $this->uploadComicImages($comicId, $titulo, $rutaCarpeta, function ($pageNum, $totalPages) use ($titulo, $pageProgressCallback) {
                // Solo actualizar estado numérico, NO al log (evita saturación por tiempo)
                $this->updateCurrentPageProgress($pageNum, $totalPages);
                if ($pageProgressCallback !== null) {
                    $pageProgressCallback($pageNum, $totalPages, $titulo);
                }
            });
            $mediaIds    = $uploadResult['media_ids'] ?? [];
            $failedPages = $uploadResult['failed_pages'] ?? [];

            if (empty($mediaIds)) {
                $this->stats['errors']++;
                $this->updatePublishStatus($comicId, 'error', null, 'No se pudo subir ninguna imagen');
                $this->addProgressLog("❌ No se pudo subir ninguna imagen para «{$titulo}»", 'error');
                return [
                    'success' => false,
                    'comic_id' => $comicId,
                    'error'   => "No se pudo subir ninguna imagen para «{$titulo}»",
                ];
            }
            $this->addProgressLog("✅ {$comicId}: " . count($mediaIds) . "/{$realTotal} imágenes subidas", 'success');
        } elseif ($this->skipImageUpload) {
            $this->addProgressLog("⏭ Subida de imágenes omitida (modo skip_image_upload)", 'warning');
        } else {
            $this->addProgressLog("⚠️ Sin imágenes para subir (directorio no encontrado: {$rutaCarpeta})", 'warning');
        }

        // ── 5. PASO B: Formatear galería como string separado por comas ──
        $imageComicString = !empty($mediaIds) ? implode(',', $mediaIds) : '';
        $this->addProgressLog("🔗 PASO B: Galería formateada (" . count($mediaIds) . " IDs)", 'info');

        // Helper para reintentar operaciones que fallan por rate-limiting de Banahosting
        $retryWithBackoff = function(string $operationName, callable $operation, int $maxRetries = 3): mixed {
            $lastException = null;
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    return $operation();
                } catch (RuntimeException $e) {
                    $lastException = $e;
                    $errorMsg = $e->getMessage();
                    if ($this->isConnectionError($errorMsg)) {
                        if ($attempt < $maxRetries) {
                            $waitTime = $attempt * 10; // 10, 20, 30 segundos
                            $this->addProgressLog("⚠️ {$operationName}: Error de conexión (intento {$attempt}/{$maxRetries}): Reintentando en {$waitTime}s...", 'warning');
                            sleep($waitTime);
                        } else {
                            $this->addProgressLog("❌ {$operationName}: Error de conexión persistente tras {$maxRetries} intentos", 'error');
                        }
                    } else {
                        // Error no es de conexión — relanzar inmediatamente
                        throw $e;
                    }
                }
            }
            throw $lastException;
        };

        // ── 6. Sincronizar taxonomías (con reintento por rate-limiting) ──
        $this->addProgressLog("🏷️ PASO C: Sincronizando taxonomías...", 'info');
        $taxonomySyncStart = microtime(true);

        try {
            $syncedIds = $retryWithBackoff('Sincronización de taxonomías', function() use ($taxData): array {
                return $this->taxonomySync->syncAll($taxData);
            });
        } catch (RuntimeException $e) {
            $this->stats['errors']++;
            $this->updatePublishStatus($comicId, 'error', null, 'Error sincronizando taxonomías: ' . $e->getMessage());
            $this->addProgressLog("❌ Error sincronizando taxonomías: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'comic_id' => $comicId,
                'error'   => "Error sincronizando taxonomías: " . $e->getMessage(),
            ];
        }

        $taxPayload = $this->taxonomySync->buildTaxonomyPayload($syncedIds);
        $this->addProgressLog("   → Taxonomías sincronizadas en " . round(microtime(true) - $taxonomySyncStart, 2) . "s", 'info');

        // ── 7. Determinar portada (primera página del cómic) ──
        $featuredMediaId = !empty($mediaIds) ? (int) end($mediaIds) : 0;
        if ($featuredMediaId > 0) {
            $this->addProgressLog("   → Portada: attachment ID {$featuredMediaId}", 'info');
        }

        // ── 8. Construir payload del post ──
        $payload = $this->buildPostPayload($titulo, $imageComicString, $taxPayload, $featuredMediaId);
        $this->addProgressLog("📝 Publicando post en WordPress...", 'info');

        // ── 9. Publicar (con reintento por rate-limiting) ──
        // En entornos LiteSpeed/CGI, /wp-json/wp/v2/posts pierde el
        // header Authorization. Si el bridge está activo, lo usamos también
        // para crear el post y evitar HTTP 401.
        $useBridge = defined('UPLOAD_USE_BRIDGE') && UPLOAD_USE_BRIDGE;
        try {
            $response = $retryWithBackoff('Creación de post', function() use ($payload, $useBridge): array {
                if ($useBridge) {
                    return $this->client->createPostViaBridge($payload);
                }
                return $this->client->createPost($payload);
            });
            $wpPostId = (int) ($response['id'] ?? 0);

            if ($wpPostId > 0) {
                // ── 10. Actualizar meta del campo image_comic (con reintento) ──
                if ($imageComicString !== '') {
                    try {
                        $retryWithBackoff('Actualización de meta image_comic', function() use ($wpPostId, $imageComicString): void {
                            $this->client->updatePostMeta($wpPostId, [
                                'image_comic'  => $imageComicString,
                                '_image_comic' => 'field_69e4761779913',
                            ]);
                        });
                        $this->addProgressLog("   → Meta image_comic actualizado", 'info');
                    } catch (RuntimeException $e) {
                        // Si aún falla tras reintentos, solo advertir (no crítico)
                        $this->addProgressLog("⚠️ No se pudo actualizar meta image_comic: " . $e->getMessage(), 'warning');
                    }
                }

                $this->stats['published']++;
                $this->updatePublishStatus($comicId, 'published', $wpPostId);
                $imagesTotal = $realTotal;
                $imagesLost = count($failedPages);
                $msg = "✅ «{$titulo}» publicado (WP Post ID: {$wpPostId}, imágenes: " . count($mediaIds) . "/{$imagesTotal}";
                if ($imagesLost > 0) {
                    $msg .= ", quedaron {$imagesLost} pendientes de rescate";
                }
                $msg .= ")";
                $this->addProgressLog($msg, 'success');
    
                // Limpiar current_comic al terminar exitosamente
                $this->currentProgress['current_comic'] = null;
                $this->writeProgressFile();
    
                return [
                    'success'     => true,
                    'comic_id'    => $comicId,
                    'wp_post_id'  => $wpPostId,
                    'titulo'      => $titulo,
                    'images_uploaded' => count($mediaIds),
                    'images_total'    => $imagesTotal,
                    'media_ids'   => $mediaIds,
                    'failed_pages'    => $failedPages,
                    'taxonomies'  => $syncedIds,
                ];
            }

            $this->stats['errors']++;
            $this->updatePublishStatus($comicId, 'error', null, 'Respuesta sin ID de post');
            $this->addProgressLog("❌ Respuesta inesperada de WordPress: sin ID de post", 'error');
            return [
                'success' => false,
                'comic_id' => $comicId,
                'error'   => "Respuesta inesperada de WordPress: sin ID de post",
            ];
        } catch (RuntimeException $e) {
            $this->stats['errors']++;
            $this->updatePublishStatus($comicId, 'error', null, $e->getMessage());
            $this->addProgressLog("❌ Error publicando «{$titulo}»: " . $e->getMessage(), 'error');

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
     * @param callable|null $pageProgressCallback fn(int $pageNum, int $totalPages, string $titulo) => void
     * @return array<string, mixed> Resultados agregados
     */
    public function publishBatch(array $comicIds, ?callable $progressCallback = null, ?callable $pageProgressCallback = null): array
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

        // Limpiar señales de stop residuales de ejecuciones anteriores
        $this->clearStopSignals();

        // Inicializar archivo de progreso
        $this->initProgressFile('publishing');
        $this->addProgressLog("🚀 Iniciando lote de " . count($comicIds) . " cómics...", 'info');

        $processedCount = 0;
        foreach ($comicIds as $index => $comicId) {
            // Verificar señal de detención HARD (stop inmediato)
            if ($this->isHardStopRequested()) {
                $this->addProgressLog("⏹ Señal de detención DURA detectada. Deteniendo inmediatamente.", 'warning');
                $this->markProgressStopped('Detenido por señal dura');
                break;
            }

            // Verificar señal de detención SOFT (terminar cómic actual y parar)
            if ($this->isSoftStopRequested()) {
                $this->addProgressLog("⏹ Señal de detención SUAVE detectada. Terminando cómic actual y deteniendo...", 'warning');
                // Si ya estamos procesando un cómic, lo terminamos primero
                if ($processedCount > 0) {
                    // El cómic actual ya se procesó arriba, solo marcamos stop
                }
                $this->markProgressStopped('Detenido después del cómic actual (señal suave)');
                break;
            }

            $result = $this->publishComic((int) $comicId, $pageProgressCallback);
            $processedCount++;
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

            // Pausa entre cómics para no saturar el servidor remoto
            if ($index < count($comicIds) - 1) {
                usleep(PUBLISH_DELAY_BETWEEN_COMICS);
            }

            // Verificar de nuevo soft stop DESPUÉS de cada cómic (para el siguiente)
            if ($this->isSoftStopRequested()) {
                $this->addProgressLog("⏹ Señal de detención suave detectada después de completar cómic.", 'warning');
                $this->markProgressStopped('Detenido después del cómic actual');
                break;
            }
        }

        // ── Segunda pasada de rescate: reintentar imágenes que fallaron ──
        $rescueStats = $this->rescueFailedImages();
        if ($rescueStats['rescued'] > 0) {
            $this->stats['images_uploaded'] += $rescueStats['rescued'];
            $this->stats['images_failed']  -= $rescueStats['rescued'];
            if ($this->stats['images_failed'] < 0) {
                $this->stats['images_failed'] = 0;
            }
        }

        // ── Guardar log de auditoría del batch completo ──
        $this->saveBatchAuditLog($comicIds, $processedCount);

        // Verificar si hemos terminado todos los cómics (auto-stop)
        $remainingCount = count($comicIds) - $processedCount;
        if ($remainingCount === 0 && $processedCount > 0) {
            // Verificar si quedan más pendientes en BD
            $pendingCount = $this->countPendingComics();
            // Armar mensaje final con estadísticas de rescate
            $finalMsg = "{$this->stats['published']} publicados, {$this->stats['errors']} errores";
            if ($rescueStats['rescued'] > 0) {
                $finalMsg .= ", {$rescueStats['rescued']} imágenes rescatadas";
            }
            if ($rescueStats['still_failed'] > 0) {
                $finalMsg .= ", {$rescueStats['still_failed']} irrecuperables";
            }
            if ($pendingCount === 0) {
                $this->markProgressSuccess("¡Todos los cómics han sido publicados! ({$finalMsg})");
            } else {
                $this->markProgressSuccess("Lote completado: {$finalMsg}. Quedan {$pendingCount} pendientes.");
            }
        } elseif ($remainingCount > 0) {
            // No se completaron todos (por stop o error)
            $this->addProgressLog("ℹ️ Quedaron {$remainingCount} cómics sin procesar.", 'info');
            if ($this->currentProgress['status'] === 'publishing') {
                $this->markProgressStopped("Proceso detenido. Procesados: {$processedCount}/" . count($comicIds));
            }
        }

        // Limpiar señales de stop al terminar
        $this->clearStopSignals();

        return $this->getStats();
    }

    /**
     * Publica todos los cómics que aún no han sido publicados.
     *
     * @param int|null $limit Límite de cómics a publicar (null = todos)
     * @param callable|null $progressCallback
     * @param callable|null $pageProgressCallback
     * @return array<string, mixed>
     */
    public function publishPending(?int $limit = null, ?callable $progressCallback = null, ?callable $pageProgressCallback = null): array
    {
        $comicIds = $this->getPendingComicIds($limit);

        if (empty($comicIds)) {
            $this->initProgressFile('completed');
            $this->addProgressLog("✅ No hay cómics pendientes por publicar", 'success');
            $this->markProgressSuccess('No hay cómics pendientes');
            return [
                'total_comics' => 0,
                'published'    => 0,
                'errors'       => 0,
                'skipped'      => 0,
                'message'      => 'No hay cómics pendientes por publicar',
                'details'      => [],
            ];
        }

        return $this->publishBatch($comicIds, $progressCallback, $pageProgressCallback);
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

    /**
     * Retorna el estado de progreso actual para polling de la UI.
     *
     * @return array<string, mixed>
     */
    public function getProgressState(): array
    {
        return $this->currentProgress;
    }

    /**
     * Obtiene el estado actual desde el archivo de progreso.
     *
     * @return array<string, mixed>|null
     */
    public static function readProgressFile(): ?array
    {
        if (!defined('PUBLISH_PROGRESS_FILE') || !file_exists(PUBLISH_PROGRESS_FILE)) {
            return null;
        }
        $data = @file_get_contents(PUBLISH_PROGRESS_FILE);
        if ($data === false) {
            return null;
        }
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Limpia el archivo de progreso.
     */
    public static function clearProgressFile(): void
    {
        if (defined('PUBLISH_PROGRESS_FILE') && file_exists(PUBLISH_PROGRESS_FILE)) {
            @unlink(PUBLISH_PROGRESS_FILE);
        }
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
            $this->addProgressLog("Error cargando datos del cómic {$comicId}: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Sube todas las imágenes de un cómic a WordPress.
     *
     * @param int    $comicId
     * @param string $titulo
     * @param string $rutaCarpeta
     * @param callable|null $pageProgressCallback fn(int $pageNum, int $totalPages) => void
     * @return array<int> IDs de los attachments creados en WordPress
     */
    /**
     * Verifica si un mensaje de error de cURL corresponde a un error de conexión/red
     * (incluyendo SSL rate-limiting) que amerita reintento con backoff.
     */
    private function isConnectionError(string $errorMsg): bool
    {
        $patterns = [
            'Could not resolve host',
            'Connection refused',
            'Connection timed out',
            'Operation timed out',
            'Failed to connect',
            'recv failure',
            'Unknown SSL protocol error',
            'SSL connection timeout',
            'SSL read timeout',
            'SSL write timeout',
            'SSL connection reset',
            'SSL: no alternative certificate',
            'error:1408F10B', // SSL routines: SSL3_GET_RECORD: wrong version number
            'error:14094410', // SSL routines: ssl3_read_bytes: sslv3 alert handshake failure
            'error:140943FC', // SSL routines: ssl3_read_bytes: sslv3 alert bad record mac
            'error:00000000', // lib(0) func(0) reason(0) — conexión reseteada
            'NSS: client certificate not found',
            'NSS error',
            'GnuTLS: A TLS packet with unexpected length was received',
            'GnuTLS: Error in pull function',
            'gnutls_handshake',
        ];
        foreach ($patterns as $pattern) {
            if (stripos($errorMsg, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sube todas las imágenes de un cómic a WordPress.
     *
     * El rate limiting global (PUBLISH_RATE_LIMIT_SECONDS) en WPClient se
     * encarga de espaciar cada petición HTTPS. Aquí solo añadimos una pausa
     * extra entre imágenes y reintento simple en caso de error de conexión.
     */
    private function uploadComicImages(int $comicId, string $titulo, string $rutaCarpeta, ?callable $pageProgressCallback = null): array
    {
        $mediaIds = [];
        $failedPages = [];

        // ── Iniciar sesión keep-alive: 1 handshake SSL para todo el cómic ──
        $this->client->beginImageSession("{$comicId} - {$titulo}");

        $images = $this->scanWebpImages($rutaCarpeta);
        if (empty($images)) {
            $this->addProgressLog("ℹ️ No se encontraron imágenes .webp en {$rutaCarpeta}", 'info');
            $this->client->endImageSession();
            return $mediaIds;
        }

        $totalImages = count($images);
        // Invertir orden para que en WordPress queden en orden ascendente
        $images = array_reverse($images);
        $titleSlug = $this->sanitizeTitleSlug($titulo);

        // ── Tracker de fallos SSL consecutivos para backoff exponencial ──
        $consecutiveSslFailures = 0;
        $maxConsecutiveSsl = defined('PUBLISH_MAX_CONSECUTIVE_SSL_FAILURES') ? (int) PUBLISH_MAX_CONSECUTIVE_SSL_FAILURES : 3;

        foreach ($images as $index => $imagePath) {
            $pageNum = $index + 1;
            $fileName = "{$comicId}-{$titleSlug}-pagina-{$pageNum}.webp";
            $altText = "{$titulo} - Página {$pageNum}";

            if ($pageProgressCallback !== null) {
                $pageProgressCallback($pageNum, $totalImages);
            }

            if ($this->isHardStopRequested()) {
                $this->addProgressLog("⏹ Detención dura detectada durante subida de imágenes.", 'warning');
                break;
            }

            $uploadSuccess = false;
            $attempts = 0;
            $maxRetries = 2;

            while ($attempts <= $maxRetries && !$uploadSuccess) {
                $attempts++;
                try {
                    // ── Si hay fallos SSL consecutivos, hacer backoff antes de reintentar ──
                    if ($consecutiveSslFailures >= $maxConsecutiveSsl) {
                        $extendedWait = min(pow(2, $consecutiveSslFailures) * 5, 120);
                        $this->addProgressLog("🛑 {$consecutiveSslFailures} fallos SSL consecutivos. Pausa extendida de {$extendedWait}s para dejar respirar al servidor...", 'warning');
                        sleep((int) $extendedWait);
                        $consecutiveSslFailures = 0; // Reset tras pausa extendida
                    }

                    $response = $this->client->uploadImage($imagePath, $fileName, $altText);
                    if (isset($response['id'])) {
                        $mediaIds[] = (int) $response['id'];
                        $uploadSuccess = true;
                        $consecutiveSslFailures = 0; // Éxito → resetear contador
                    }
                } catch (RuntimeException $e) {
                    $errorMsg = $e->getMessage();
                    $isSslError = (stripos($errorMsg, 'Unknown SSL protocol error') !== false)
                               || (stripos($errorMsg, 'SSL connection') !== false)
                               || (stripos($errorMsg, 'SSL:') !== false)
                               || (stripos($errorMsg, 'SSL read') !== false)
                               || (stripos($errorMsg, 'SSL write') !== false)
                               || (stripos($errorMsg, 'gnutls_handshake') !== false);

                    if ($isSslError) {
                        $consecutiveSslFailures++;

                        if ($consecutiveSslFailures >= $maxConsecutiveSsl) {
                            // Se manejara al inicio del siguiente intento/iteración
                            $this->addProgressLog(
                                "⚠️ SSL Error (pág {$pageNum}, intento {$attempts}/{$maxRetries}, fallo consecutivo #{$consecutiveSslFailures})",
                                'warning'
                            );
                        }
                    }

                    if ($this->isConnectionError($errorMsg) && $attempts <= $maxRetries) {
                        $wait = $attempts * 10 + ($consecutiveSslFailures * 5);
                        $wait = min($wait, 60); // Cap en 60s por intento individual
                        $this->addProgressLog("⚠️ Error de conexión (pág {$pageNum}, intento {$attempts}/{$maxRetries}): esperando {$wait}s...", 'warning');
                        sleep($wait);
                    } elseif ($attempts <= $maxRetries) {
                        usleep(1500000); // 1.5s en vez de 3s, mas agil para errores no-SSL
                        $this->addProgressLog("⚠️ Reintentando pág {$pageNum} (intento {$attempts}/{$maxRetries})...", 'warning');
                    } else {
                        $this->stats['images_failed']++;
                        $this->addProgressLog("❌ Falló página {$pageNum} tras " . ($maxRetries + 1) . " intentos: " . substr($errorMsg, 0, 150), 'error');
                    }
                }
            }

            if (!$uploadSuccess) {
                $this->addProgressLog("⚠️ Imagen {$pageNum}/{$totalImages} no se pudo subir", 'warning');
                $failedPages[] = [
                    'page_num'   => $pageNum,
                    'image_path' => $imagePath,
                    'file_name'  => $fileName,
                    'alt_text'   => $altText,
                ];
            }

            // ── Pausa ALEATORIA entre imágenes para no parecer un ataque ──
            // El rate limiter global ya mete 1.5s. La pausa aleatoria añade 0.5-2.0s extra.
            // Total efectivo: 2.0-3.5s entre imágenes, variando aleatoriamente.
            $delayMin = defined('PUBLISH_DELAY_IMAGES_MIN') ? (int) PUBLISH_DELAY_IMAGES_MIN : 500000;
            $delayMax = defined('PUBLISH_DELAY_IMAGES_MAX') ? (int) PUBLISH_DELAY_IMAGES_MAX : 2000000;
            if ($delayMax > $delayMin) {
                $randomDelay = rand($delayMin, $delayMax);
                usleep($randomDelay);
            }
        }

        // ── Finalizar sesión keep-alive (cerrar handle SSL compartido) ──
        $this->client->endImageSession();

        return [
            'media_ids'    => $mediaIds,
            'failed_pages' => $failedPages,
        ];
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
        // Sanitizar título para HTTP: elimina caracteres que disparan
        // WAFs como cPGuard / Imunify360 (zero-width, bidi, control chars)
        $titulo = $this->sanitizeForHttp($titulo);
        if ($titulo === '') {
            $titulo = 'Sin título';
        }

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
        if ($imageComicString !== '') {
            $payload['acf']['image_comic'] = $imageComicString;
        }

        // ── Taxonomías ──
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
            $this->addProgressLog("Error actualizando estado de publicación: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Asegura que las columnas de publicación existan en la tabla.
     */
    private function ensurePublishColumns(): void
    {
        try {
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
                $this->addProgressLog("✅ Columnas de publicación añadidas a comics_descargados", 'success');
            }

            // ── Asegurar columna imagenes_eliminadas ──
            $stmt = $this->pdo->query("SHOW COLUMNS FROM comics_descargados LIKE 'imagenes_eliminadas'");
            if (!$stmt->fetch()) {
                $this->pdo->exec(
                    "ALTER TABLE comics_descargados
                     ADD COLUMN imagenes_eliminadas TINYINT(1) DEFAULT 0
                     COMMENT '1 = imágenes borradas del disco tras publicar en WP (optimizar espacio)'
                     AFTER wp_publish_error"
                );
                $this->addProgressLog("✅ Columna imagenes_eliminadas añadida a comics_descargados", 'success');
            }
        } catch (Exception $e) {
            $this->addProgressLog("Aviso: no se pudieron verificar/crear columnas de publicación: " . $e->getMessage(), 'warning');
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
            $this->addProgressLog("Error obteniendo cómics pendientes: " . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Cuenta cuántos cómics pendientes hay en BD.
     *
     * @return int
     */
    private function countPendingComics(): int
    {
        try {
            $this->ensurePublishColumns();
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM comics_descargados
                 WHERE (wp_publish_status IS NULL OR wp_publish_status IN ('pending', 'error'))
                   AND estado = 'completo'"
            );
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
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

    /**
     * Sanitiza un string para envío HTTP seguro, eliminando caracteres
     * que firewalls WAF (cPGuard, Imunify360, mod_security) pueden
     * interpretar como intentos de inyección o caracteres inválidos.
     *
     * Elimina:
     *  - Caracteres de control ASCII (0x00-0x1F excepto \t, \n, \r)
     *  - Caracteres de control C1 (0x80-0x9F)
     *  - Zero-width spaces y marcas de dirección Unicode (Bidi)
     *  - Separadores de línea/párrafo Unicode
     *  - BOM (Byte Order Mark) U+FEFF
     *  - Caracteres de sustitución y no asignados
     *
     * Preserva: letras, números, acentos, ñ, puntuación común,
     * emojis y caracteres CJK legítimos.
     *
     * @param string $text Texto a sanitizar
     * @return string Texto limpio, seguro para tránsito HTTP
     */
    private function sanitizeForHttp(string $text): string
    {
        // Normalizar a UTF-8 NFC (compatibilidad máxima con WordPress)
        if (function_exists('normalizer_normalize')) {
            $text = @normalizer_normalize($text, \Normalizer::FORM_C);
            if ($text === false) {
                $text = ''; // String corrupto → vacío seguro
            }
        }

        // Eliminar:
        // - Control chars ASCII excepto tab, newline, carriage return
        // - C1 control chars (0x80-0x9F)
        // - Zero-width spaces: U+200B (ZWSP), U+200C (ZWNJ), U+200D (ZWJ), U+FEFF (BOM)
        // - Bidi directionals: U+200E (LRM), U+200F (RLM), U+202A-U+202E
        // - Line/paragraph separators: U+2028, U+2029
        $text = preg_replace(
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{FEFF}]/u',
            '',
            $text
        );

        // Colapsar espacios múltiples consecutivos
        $text = preg_replace('/[ \t]+/', ' ', $text);

        return trim($text);
    }

    // ─────────────────────────────────────────────────────────────
    //  STOP SIGNAL HANDLING
    // ─────────────────────────────────────────────────────────────

    /**
     * Verifica si se ha solicitado una detención dura (inmediata).
     *
     * @return bool
     */
    private function isHardStopRequested(): bool
    {
        return defined('PUBLISH_STOP_FILE') && file_exists(PUBLISH_STOP_FILE);
    }

    /**
     * Verifica si se ha solicitado una detención suave (después del comic actual).
     *
     * @return bool
     */
    private function isSoftStopRequested(): bool
    {
        return defined('PUBLISH_SOFT_STOP_FILE') && file_exists(PUBLISH_SOFT_STOP_FILE);
    }

    /**
     * Limpia las señales de stop.
     */
    private function clearStopSignals(): void
    {
        if (defined('PUBLISH_STOP_FILE') && file_exists(PUBLISH_STOP_FILE)) {
            @unlink(PUBLISH_STOP_FILE);
        }
        if (defined('PUBLISH_SOFT_STOP_FILE') && file_exists(PUBLISH_SOFT_STOP_FILE)) {
            @unlink(PUBLISH_SOFT_STOP_FILE);
        }
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
     * Guarda un log de auditoría completo del batch en formato JSON.
     *
     * Cada archivo contiene: estadísticas, resultados detallados por cómic,
     * IDs de imágenes subidas/fallidas, taxonomías sincronizadas, timestamps
     * y el log de progreso completo. Esto permite auditoría post-mortem y
     * diagnóstico de fallos recurrentes sin depender de la UI.
     *
     * @param array<int> $comicIds       Lista de IDs del batch
     * @param int        $processedCount Cuántos se procesaron realmente
     */
    /**
     * Segunda pasada de rescate: reintenta subir imágenes que fallaron durante
     * la primera pasada, y repara los posts de WordPress insertando los nuevos
     * attachment IDs en la posición correcta dentro de image_comic.
     *
     * Estrategia:
     * 1. Esperar 60s para que el rate-limiting de Banahosting se resetee
     * 2. Subir cada imagen fallida con handle NO persistente (fresh cada vez)
     * 3. Si éxito, empalmar el nuevo attachment ID en la posición correcta
     * 4. Actualizar post meta (image_comic) vía updatePostMeta con reintento
     *
     * @return array{rescued: int, still_failed: int} Estadísticas del rescate
     */
    private function rescueFailedImages(): array
    {
        $stats = ['rescued' => 0, 'still_failed' => 0];

        // ── 1. Recolectar todas las páginas fallidas de todos los cómics ──
        $toRescue = [];
        foreach ($this->stats['details'] as $detail) {
            if (!empty($detail['success']) && !empty($detail['failed_pages']) && !empty($detail['wp_post_id'])) {
                $toRescue[] = [
                    'comic_id'     => $detail['comic_id'],
                    'wp_post_id'   => $detail['wp_post_id'],
                    'titulo'       => $detail['titulo'] ?? '',
                    'failed_pages' => $detail['failed_pages'],
                    'media_ids'    => $detail['media_ids'] ?? [],
                    'images_total' => $detail['images_total'] ?? count($detail['media_ids'] ?? []) + count($detail['failed_pages'] ?? []),
                ];
            }
        }

        if (empty($toRescue)) {
            $this->addProgressLog("🎯 Rescate: No hay imágenes pendientes por rescatar.", 'info');
            return $stats;
        }

        $totalFailed = array_sum(array_map(fn($c) => count($c['failed_pages']), $toRescue));
        $this->addProgressLog("", 'info');
        $this->addProgressLog("🎯 ── SEGUNDA PASADA DE RESCATE ──", 'info');
        $this->addProgressLog("🎯 Rescatando {$totalFailed} imágenes fallidas en " . count($toRescue) . " cómics...", 'info');

        // ── 2. Esperar 60s para que el rate-limiting de Banahosting se resetee ──
        $this->addProgressLog("⏳ Esperando 60s para que el servidor se calme antes del rescate...", 'info');
        sleep(60);
        $this->addProgressLog("▶️ Iniciando rescate...", 'info');

        // ── 3. Para cada cómic, reintentar sus páginas fallidas ──
        foreach ($toRescue as $entry) {
            $comicId   = $entry['comic_id'];
            $wpPostId  = $entry['wp_post_id'];
            $titulo    = $entry['titulo'];
            $mediaIds  = $entry['media_ids'];
            $total     = $entry['images_total'];
            $failed    = $entry['failed_pages'];

            $this->addProgressLog("🔧 Rescatando «{$titulo}» (ID {$comicId}, WP Post ID {$wpPostId}): " . count($failed) . " imágenes pendientes...", 'info');

            // Iniciar sesión keep-alive para este cómic
            $this->client->beginImageSession("{$comicId} - {$titulo} [RESCATE]");

            $repairedCount = 0;
            foreach ($failed as $fp) {
                $pageNum  = $fp['page_num'];
                $filePath = $fp['image_path'];
                $fileName = $fp['file_name'];
                $altText  = $fp['alt_text'];

                // Verificar señal de stop
                if ($this->isHardStopRequested()) {
                    $this->addProgressLog("⏹ Detención dura durante rescate.", 'warning');
                    break 2;
                }

                try {
                    // ── Aplicar rate limiting manual (no queremos saturar) ──
                    usleep(1500000); // 1.5s entre cada intento de rescate

                    $response = $this->client->uploadImage($filePath, $fileName, $altText);

                    if (isset($response['id'])) {
                        $newAttachmentId = (int) $response['id'];

                        // ── 4. Insertar el ID en la posición correcta del array ──
                        // $mediaIds se construye en orden INVERSO en uploadComicImages():
                        //   las imágenes se escanean ascendentes (p1, p2, ..., pN),
                        //   se invierten con array_reverse, y se suben como pN, pN-1, ..., p1.
                        // Por tanto, en $mediaIds el índice 0 = página N, índice N-1 = página 1.
                        // Para pageNum = P (1-indexed), posición = totalImages - P
                        $insertPos = $total - $pageNum;
                        if ($insertPos >= count($mediaIds)) {
                            $mediaIds[] = $newAttachmentId;
                        } else {
                            array_splice($mediaIds, $insertPos, 0, $newAttachmentId);
                        }
                        $newImageComic = implode(',', $mediaIds);

                        // ── 5. Actualizar post meta (con reintento) ──
                        $metaOk = false;
                        $metaAttempts = 0;
                        $metaMaxRetries = 3;
                        while (!$metaOk && $metaAttempts < $metaMaxRetries) {
                            $metaAttempts++;
                            try {
                                $this->client->updatePostMeta($wpPostId, [
                                    'image_comic'  => $newImageComic,
                                    '_image_comic' => 'field_69e4761779913',
                                ]);
                                $metaOk = true;
                            } catch (RuntimeException $metaE) {
                                if ($metaAttempts < $metaMaxRetries) {
                                    $this->addProgressLog("⚠️ Reintentando meta image_comic (intento {$metaAttempts}/{$metaMaxRetries})...", 'warning');
                                    sleep($metaAttempts * 5);
                                } else {
                                    throw $metaE;
                                }
                            }
                        }

                        $repairedCount++;
                        $stats['rescued']++;
                        $this->addProgressLog("   ✅ Rescatada página {$pageNum} → attachment ID {$newAttachmentId}", 'success');
                        $this->log("🎯 [RESCATE] Cómic {$comicId}: página {$pageNum} rescatada (attachment {$newAttachmentId})");
                    }
                } catch (RuntimeException $e) {
                    $stats['still_failed']++;
                    $this->addProgressLog("   ❌ Página {$pageNum} sigue fallando tras rescate: " . substr($e->getMessage(), 0, 100), 'error');
                    $this->log("🎯 [RESCATE] Cómic {$comicId}: página {$pageNum} IRRECUPERABLE: " . $e->getMessage());
                }
            }

            $this->client->endImageSession();

            if ($repairedCount > 0) {
                $this->addProgressLog("🎉 «{$titulo}»: {$repairedCount}/" . count($failed) . " imágenes rescatadas exitosamente.", 'success');
            }
        }

        $this->addProgressLog("🎯 Rescate completado: {$stats['rescued']} rescatadas, {$stats['still_failed']} siguen fallando.", 'info');

        return $stats;
    }

    private function saveBatchAuditLog(array $comicIds, int $processedCount): void
    {
        // Solo guardar si hay resultados que valga la pena auditar
        if (empty($this->stats['details'])) {
            return;
        }

        $batchLogDir = defined('PUBLISH_BATCH_LOG_DIR') ? PUBLISH_BATCH_LOG_DIR : __DIR__ . '/../../logs/batches';
        if (!is_dir($batchLogDir)) {
            @mkdir($batchLogDir, 0777, true);
        }

        $batchId = date('Ymd_His');
        $auditData = [
            'batch_id'        => $batchId,
            'started_at'      => date('c'),
            'ended_at'        => date('c'),
            'trigger'         => php_sapi_name() === 'cli' ? 'CLI' : 'Web',
            'total_requested' => count($comicIds),
            'total_processed' => $processedCount,
            'stop_reason'     => $this->currentProgress['status'] ?? 'unknown',
            'stats'           => $this->stats,
            'comics'          => $this->stats['details'],
            'progress_log'    => $this->currentProgress['log'] ?? [],
        ];

        // ── Enriquecer cada cómic con conteo de imágenes fallidas ──
        foreach ($auditData['comics'] as &$comic) {
            if (isset($comic['media_ids']) && is_array($comic['media_ids'])) {
                $comic['images_succeeded'] = count($comic['media_ids']);
            } else {
                $comic['images_succeeded'] = 0;
            }
        }
        unset($comic);

        $filename = $batchLogDir . '/batch_' . $batchId . '.json';
        $json = json_encode($auditData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        @file_put_contents($filename, $json, LOCK_EX);

        // También registrar en el log tradicional
        $this->log("📋 Log de auditoría guardado: {$filename} (" . count($this->stats['details']) . " cómics, {$this->stats['published']} publicados, {$this->stats['errors']} errores)");
    }
}
