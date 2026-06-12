<?php
/**
 * WPClient.php
 *
 * Cliente HTTP para la REST API de WordPress.
 * Maneja autenticación Basic Auth (Application Passwords),
 * peticiones GET/POST/PUT/DELETE, subida de medios y logging.
 *
 * @package ScrapApp
 * @subpackage WP
 */

class WPClient
{
    /** @var string URL base del sitio WordPress (ej: http://localhost:10003) */
    private string $baseUrl;

    /** @var string Cabecera Authorization: Basic <token> */
    private string $authHeader;

    /** @var int Timeout en segundos para peticiones cURL */
    private int $timeout;

    /** @var int Máximo de reintentos por petición fallida */
    private int $maxRetries;

    /** @var array<string, mixed> Estadísticas acumuladas de la sesión */
    private array $stats;

    // ── GLOBAL Rate Limiter (compartido entre todas las instancias) ──
    /** @var float Timestamp de la última petición (microtime) */
    private static float $lastRequestTime = 0.0;

    // ── SSL Meltdown Protection ──
    /**
     * Timestamp (microtime) hasta la cual debemos esperar antes de permitir
     * nuevas conexiones. Se activa cuando un handle FRESCO falla con errno 35
     * (Unknown SSL protocol error), lo que indica que el stack SSL del servidor
     * colapsó y necesita tiempo para recuperarse.
     *
     * Es static porque el meltdown afecta a TODAS las instancias: si el stack
     * SSL del servidor colapsó, ninguna conexión (de ningún WPClient) funcionará.
     */
    private static float $sslMeltdownUntil = 0.0;

    /** @var string Identificador del cómic actual para logs de diagnóstico (ej: "627083 - Titulo del comic") */
    private string $comicIdentifier = '';

    /**
     * @param string $baseUrl  URL base del sitio WordPress (sin trailing slash)
     * @param string $username Usuario de WordPress con Application Password
     * @param string $password Application Password (con espacios)
     * @param array<string, mixed> $options Opciones adicionales
     */
    public function __construct(
        string $baseUrl,
        string $username,
        string $password,
        array $options = []
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authHeader = 'Authorization: Basic ' . base64_encode("{$username}:{$password}");
        $this->timeout = (int) ($options['timeout'] ?? 60);
        $this->maxRetries = (int) ($options['max_retries'] ?? 3);
        $this->stats = [
            'requests'   => 0,
            'success'    => 0,
            'errors'     => 0,
            'images_uploaded' => 0,
            'posts_created'   => 0,
        ];
    }

    /**
     * Realiza una petición GET a la REST API.
     *
     * @param string $endpoint Ej: "/wp/v2/posts" o "/wp/v2/media"
     * @param array<string, mixed> $queryParams Parámetros de consulta
     * @return array<string, mixed> Respuesta decodificada
     * @throws RuntimeException Si falla tras reintentos
     */
    public function get(string $endpoint, array $queryParams = []): array
    {
        return $this->request('GET', $endpoint, $queryParams);
    }

    /**
     * Realiza una petición POST a la REST API.
     *
     * @param string $endpoint Ej: "/wp/v2/posts"
     * @param array<string, mixed> $data Payload a enviar como JSON
     * @return array<string, mixed> Respuesta decodificada
     * @throws RuntimeException Si falla tras reintentos
     */
    public function post(string $endpoint, array $data): array
    {
        return $this->request('POST', $endpoint, [], $data);
    }

    /**
     * Sube un archivo de imagen a WordPress como attachment (media).
     *
     * PASO A del flujo: Subida de imágenes al endpoint /wp/v2/media.
     *
     * ESTRATEGIA DE CONEXIÓN (Frente 4 — Junio 2026):
     * - Por defecto, SIEMPRE usa handles cURL frescos (nuevo handshake SSL por imagen).
     *   Esto evita que el handle persistente corrompa el stack SSL del servidor,
     *   lo cual causaba ERR_CONNECTION_RESET en todo gluglux.com.
     * - Si PERSISTENT_CURL_ENABLED=true en config.php, se usa el handle persistente
     *   (solo recomendado en hostings con keep-alive de muy larga duración).
     * - Si un handle FRESCO falla con errno 35 (Unknown SSL protocol error),
     *   se activa SSL_MELTDOWN_COOLDOWN: pausa global de 3 minutos para que
     *   el stack SSL del servidor se recupere.
     *
     * @param string $filePath Ruta absoluta del archivo en disco
     * @param string $fileName Nombre del archivo (slug)
     * @param string|null $altText Texto alternativo
     * @return array<string, mixed> Respuesta con el ID del attachment y URL
     * @throws RuntimeException Si falla la subida
     */
    public function uploadImage(string $filePath, string $fileName, ?string $altText = null): array
    {
        // ── Global Rate Limiter ──
        self::enforceRateLimit();

        // ── SSL Meltdown Cooldown: esperar si el stack SSL del servidor colapsó ──
        self::waitForSslMeltdownCooldown();

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("Archivo no encontrado o no legible: {$filePath}");
        }

        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            throw new RuntimeException("No se pudo leer el archivo: {$filePath}");
        }

        $mimeType = $this->detectMimeType($filePath);

        // ── Base64 Bridge: evade WAF @validateByteRange 1-255 ──
        // Cuando está habilitado, las imágenes se codifican en base64 y se envían
        // como JSON al bridge wp-media-bridge.php en lugar de como binario directo
        // al REST API. Esto evade la regla del WAF Imunify360/ModSecurity que
        // bloquea bytes NULL (comunes en imágenes .webp) en peticiones POST.
        $useBridge = defined('UPLOAD_USE_BRIDGE') && UPLOAD_USE_BRIDGE;
        $endpoint = $useBridge
            ? (defined('UPLOAD_BRIDGE_ENDPOINT') ? UPLOAD_BRIDGE_ENDPOINT : '/wp-media-bridge.php')
            : '/wp-json/wp/v2/media';

        $this->stats['requests']++;

        // ── Determinar si usar handle persistente (legacy) o handle fresco ──
        $usePersistent = defined('PERSISTENT_CURL_ENABLED') && PERSISTENT_CURL_ENABLED;

        // ── Handle persistente (legacy, solo si se habilita explícitamente) ──
        if ($usePersistent) {
            $result = $this->uploadImagePersistent($endpoint, $fileData, $fileName, $mimeType, $altText, $useBridge);
        } else {
            // ── Handle fresco (comportamiento por defecto, más seguro) ──
            $result = $this->uploadImageFresh($endpoint, $fileData, $fileName, $mimeType, $altText, $useBridge);
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded) || !isset($decoded['id'])) {
            $this->stats['errors']++;

            // ── DEBUG: respuesta HTTP 200 pero sin ID de attachment ──
            $this->writeDebugUploadLog(
                $fileName,
                200,
                0,
                'HTTP OK pero respuesta inesperada (sin ID)',
                $result,
                ($useBridge ? 'bridge base64' : 'handle fresco') . ' - respuesta sin ID de attachment'
            );

            throw new RuntimeException(
                "Respuesta inesperada al subir {$fileName}: no se recibió ID"
            );
        }

        $this->stats['success']++;
        $this->stats['images_uploaded']++;

        return $decoded;
    }

    /**
     * Sube una imagen usando un handle cURL fresco (nuevo handshake SSL).
     *
     * Este es el método por defecto. No reutiliza conexiones SSL, lo que
     * elimina el riesgo de corromper el stack SSL del servidor.
     *
     * Si falla con errno 35 (Unknown SSL protocol error), activa el
     * SSL meltdown cooldown para que el servidor se recupere.
     *
     * Modo bridge (useBridge=true): codifica la imagen en base64,
     * la envía como JSON al bridge wp-media-bridge.php, evadiendo la
     * regla @validateByteRange 1-255 del WAF Imunify360.
     *
     * @param string      $endpoint  Endpoint (REST API o bridge)
     * @param string      $fileData  Contenido binario del archivo
     * @param string      $fileName  Nombre del archivo
     * @param string      $mimeType  MIME type detectado
     * @param string|null $altText   Texto alternativo para la imagen
     * @param bool        $useBridge Si es true, envía como base64 JSON al bridge
     * @return string Cuerpo de la respuesta HTTP (JSON)
     * @throws RuntimeException Si falla la subida
     */
    private function uploadImageFresh(
        string $endpoint,
        string $fileData,
        string $fileName,
        string $mimeType,
        ?string $altText = null,
        bool $useBridge = false
    ): string {
        // ── Construir headers y body según el modo ──
        if ($useBridge) {
            // Modo bridge: JSON con base64. Evade @validateByteRange 1-255
            // del WAF porque solo viajan caracteres alfanuméricos seguros.
            $payload = json_encode([
                'filename'    => $fileName,
                'base64_data' => base64_encode($fileData),
                'mime_type'   => $mimeType,
                'alt_text'    => $altText ?? '',
            ]);
            $headers = [
                $this->authHeader,
                'Content-Type: application/json',
                'Cache-Control: no-cache',
            ];
            $postFields = $payload;
            $modeLabel = 'bridge base64';
        } else {
            // Modo directo: binario al REST API (puede activar WAF)
            $headers = [
                $this->authHeader,
                'Content-Type: ' . $mimeType,
                'Content-Disposition: attachment; filename="' . $fileName . '"',
                'Cache-Control: no-cache',
            ];
            $postFields = $fileData;
            $modeLabel = 'handle fresco';
        }

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
            // No keep-alive: cada imagen es una conexión independiente
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_FRESH_CONNECT  => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->stats['errors']++;
            $errorMsg = $error ?: "HTTP {$httpCode}";

            // ── SSL Meltdown Detection ──
            // errno 35 en un handle FRESCO significa que el stack SSL del
            // servidor colapsó completamente. Activamos el cooldown global.
            if ($curlErrno === 35) {
                $cooldown = defined('SSL_MELTDOWN_COOLDOWN_SECONDS') ? (int) SSL_MELTDOWN_COOLDOWN_SECONDS : 180;
                self::$sslMeltdownUntil = microtime(true) + $cooldown;
                $this->log("🚨 SSL MELTDOWN DETECTADO (errno 35 en {$modeLabel}). Activando cooldown de {$cooldown}s.");
            }

            // ── DEBUG ──
            $this->writeDebugUploadLog(
                $fileName,
                $httpCode,
                $curlErrno,
                $errorMsg,
                $response,
                $modeLabel
            );

            throw new RuntimeException(
                "Error subiendo imagen {$fileName}: {$errorMsg}" .
                ($response ? ' - ' . substr($response, 0, 200) : '')
            );
        }

        return $response;
    }

    /**
     * Sube una imagen usando el handle cURL persistente (legacy).
     *
     * Solo se usa si PERSISTENT_CURL_ENABLED=true en config.php.
     * Reutiliza la conexión SSL entre imágenes de un mismo cómic para
     * reducir handshakes, pero puede causar ERR_CONNECTION_RESET en el
     * servidor si el keep-alive timeout del servidor es menor que el
     * intervalo entre imágenes.
     *
     * Soporta el modo bridge (base64 JSON) igual que uploadImageFresh().
     *
     * @param string      $endpoint  Endpoint REST API o bridge
     * @param string      $fileData  Contenido binario del archivo
     * @param string      $fileName  Nombre del archivo
     * @param string      $mimeType  MIME type detectado
     * @param string|null $altText   Texto alternativo para la imagen
     * @param bool        $useBridge Si es true, envía como base64 JSON al bridge
     * @return string Cuerpo de la respuesta HTTP (JSON)
     * @throws RuntimeException Si falla la subida
     */
    private function uploadImagePersistent(
        string $endpoint,
        string $fileData,
        string $fileName,
        string $mimeType,
        ?string $altText = null,
        bool $useBridge = false
    ): string {
        // ── Construir headers y body según el modo ──
        if ($useBridge) {
            $payload = json_encode([
                'filename'    => $fileName,
                'base64_data' => base64_encode($fileData),
                'mime_type'   => $mimeType,
                'alt_text'    => $altText ?? '',
            ]);
            $headers = [
                $this->authHeader,
                'Content-Type: application/json',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
            ];
            $postFields = $payload;
            $freshHeaders = [
                $this->authHeader,
                'Content-Type: application/json',
                'Cache-Control: no-cache',
            ];
            $modeLabel = 'bridge base64 (persistent)';
            $modeLabelRetry = 'bridge base64 (handle fresco, 2do intento)';
        } else {
            $headers = [
                $this->authHeader,
                'Content-Type: ' . $mimeType,
                'Content-Disposition: attachment; filename="' . $fileName . '"',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
            ];
            $postFields = $fileData;
            $freshHeaders = [
                $this->authHeader,
                'Content-Type: ' . $mimeType,
                'Content-Disposition: attachment; filename="' . $fileName . '"',
                'Cache-Control: no-cache',
            ];
            $modeLabel = 'keep-alive (legacy persistente)';
            $modeLabelRetry = 'handle fresco (2do intento)';
        }

        // Primer intento: keep-alive persistente
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_FORBID_REUSE   => false,
            CURLOPT_FRESH_CONNECT  => false,
            CURLOPT_TCP_KEEPALIVE  => true,
            CURLOPT_TCP_KEEPIDLE   => 60,
            CURLOPT_TCP_KEEPINTVL  => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $error    = curl_error($ch);

        // Si falla con keep-alive, reintentar con handle fresco
        if (($response === false || $httpCode < 200 || $httpCode >= 300)) {
            $firstError = $error ?: "HTTP {$httpCode}";
            $firstResponse = $response;
            $firstHttpCode = $httpCode;
            $firstCurlErrno = $curlErrno;

            $this->writeDebugUploadLog(
                $fileName,
                $firstHttpCode,
                $firstCurlErrno,
                $firstError,
                $firstResponse,
                "{$modeLabel} (1er intento) - reintentando con {$modeLabelRetry}"
            );

            curl_close($ch);

            // Reintentar con handle fresco (mismo body/headers sin keep-alive)
            $ch = curl_init($this->baseUrl . $endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $freshHeaders,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
                CURLOPT_FORBID_REUSE   => true,
                CURLOPT_FRESH_CONNECT  => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $error    = curl_error($ch);

            if ($response === false || $httpCode < 200 || $httpCode >= 300) {
                curl_close($ch);
                $this->stats['errors']++;
                $errorMsg = $error ?: "HTTP {$httpCode}";

                if ($curlErrno === 35) {
                    $cooldown = defined('SSL_MELTDOWN_COOLDOWN_SECONDS') ? (int) SSL_MELTDOWN_COOLDOWN_SECONDS : 180;
                    self::$sslMeltdownUntil = microtime(true) + $cooldown;
                    $this->log("🚨 SSL MELTDOWN DETECTADO (errno 35 en {$modeLabelRetry}). Cooldown de {$cooldown}s.");
                }

                $this->writeDebugUploadLog(
                    $fileName,
                    $httpCode,
                    $curlErrno,
                    $errorMsg,
                    $response,
                    "{$modeLabelRetry} - keep-alive previo falló con: {$firstError}"
                );

                throw new RuntimeException(
                    "Error subiendo imagen {$fileName}: {$errorMsg}" .
                    ($response ? ' - ' . substr($response, 0, 200) : '') .
                    " (keep-alive falló con: {$firstError})"
                );
            }
        } elseif ($response === false || $httpCode < 200 || $httpCode >= 300) {
            curl_close($ch);
            $this->stats['errors']++;
            $errorMsg = $error ?: "HTTP {$httpCode}";

            if ($curlErrno === 35) {
                $cooldown = defined('SSL_MELTDOWN_COOLDOWN_SECONDS') ? (int) SSL_MELTDOWN_COOLDOWN_SECONDS : 180;
                self::$sslMeltdownUntil = microtime(true) + $cooldown;
                $this->log("🚨 SSL MELTDOWN DETECTADO (errno 35 en {$modeLabel}). Cooldown de {$cooldown}s.");
            }

            $this->writeDebugUploadLog(
                $fileName,
                $httpCode,
                $curlErrno,
                $errorMsg,
                $response,
                $modeLabel
            );

            throw new RuntimeException(
                "Error subiendo imagen {$fileName}: {$errorMsg}" .
                ($response ? ' - ' . substr($response, 0, 200) : '')
            );
        }

        curl_close($ch);
        return $response;
    }

    /**
     * Inicia una sesión de imágenes para un cómic.
     *
     * Antes del Frente 4 (Junio 2026), este método iniciaba una sesión
     * keep-alive SSL persistente. Ahora que las conexiones son frescas
     * por defecto, solo registra el identificador del cómic para logs.
     *
     * Se mantiene por compatibilidad hacia atrás con WPPublisher.
     *
     * @param string $comicIdentifier Identificador legible del cómic para logs
     *        (ej: "627083 - [Nekochigura (Sachi)] Yasashii Kodoku")
     */
    public function beginImageSession(string $comicIdentifier = ''): void
    {
        $this->endImageSession();
        $this->comicIdentifier = $comicIdentifier;
    }

    /**
     * Finaliza la sesión de imágenes.
     *
     * Antes del Frente 4, esto cerraba el handle cURL persistente.
     * Ahora solo limpia el identificador del cómic.
     *
     * Se mantiene por compatibilidad hacia atrás con WPPublisher.
     */
    public function endImageSession(): void
    {
        $this->comicIdentifier = '';
    }

    /**
     * Busca un término de taxonomía en WordPress por nombre y taxonomía.
     * Si no existe, lo CREA.
     *
     * @param string $taxonomy Taxonomía slug (ej: "post_tag", "universo", "idioma")
     * @param string $termName Nombre del término
     * @return int ID numérico del término en WordPress
     * @throws RuntimeException Si falla la operación
     */
    public function ensureTermExists(string $taxonomy, string $termName): int
    {
        // 1. Obtener el endpoint correcto según la taxonomía
        //    WordPress REST API usa /wp/v2/tags para post_tag (no /wp/v2/post_tag)
        $endpoint = '/wp-json/wp/v2/' . $this->getTaxonomyEndpoint($taxonomy);
        $params = ['search' => $termName, 'per_page' => 10];

        try {
            $results = $this->get($endpoint, $params);
            if (is_array($results)) {
                foreach ($results as $term) {
                    if (is_array($term) && isset($term['name'], $term['id'])) {
                        if (mb_strtolower(trim($term['name']), 'UTF-8') === mb_strtolower(trim($termName), 'UTF-8')) {
                            return (int) $term['id'];
                        }
                    }
                }
            }
        } catch (RuntimeException $e) {
            // Si falla la búsqueda, intentamos crear directamente
        }

        // 2. No existe → CREAR
        $payload = [
            'name' => $termName,
            'slug' => $this->sanitizeSlug($termName),
        ];

        try {
            $result = $this->post($endpoint, $payload);
            if (is_array($result) && isset($result['id'])) {
                return (int) $result['id'];
            }
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                "No se pudo crear el término '{$termName}' en taxonomía '{$taxonomy}': " . $e->getMessage()
            );
        }

        throw new RuntimeException(
            "Respuesta inesperada al crear término '{$termName}' en '{$taxonomy}'"
        );
    }

    /**
     * Publica un post en WordPress (POST /wp/v2/posts).
     *
     * @param array<string, mixed> $payload Payload completo del post
     * @return array<string, mixed> Respuesta con el ID del post creado
     * @throws RuntimeException Si falla la publicación
     */
    public function createPost(array $payload): array
    {
        $this->stats['requests']++;

        $ch = curl_init($this->baseUrl . '/wp-json/wp/v2/posts');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                $this->authHeader,
                'Content-Type: application/json',
                'Cache-Control: no-cache',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_FRESH_CONNECT  => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->stats['errors']++;
            $errorMsg = $error ?: "HTTP {$httpCode}";
            $detail = '';
            if ($response) {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && isset($decoded['message'])) {
                    $detail = ' - ' . $decoded['message'];
                } else {
                    $detail = ' - ' . substr($response, 0, 300);
                }
            }
            throw new RuntimeException(
                "Error creando post: {$errorMsg}{$detail}"
            );
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['id'])) {
            $this->stats['errors']++;
            throw new RuntimeException(
                "Respuesta inesperada al crear post: no se recibió ID"
            );
        }

        $this->stats['success']++;
        $this->stats['posts_created']++;

        return $decoded;
    }

    /**
     * Crea un post en WordPress usando el bridge wp-media-bridge.php.
     *
     * En entornos LiteSpeed/CGI, el header Authorization es eliminado
     * en peticiones a /wp-json/wp/v2/posts, causando HTTP 401.
     * Esta ruta alternativa evade ese problema pasando por el bridge,
     * que es un archivo PHP standalone y no sufre el stripping del header.
     *
     * El bridge autentica con las mismas credenciales (Basic Auth) y
     * ejecuta wp_insert_post(), wp_set_post_terms() y update_post_meta()
     * directamente dentro de WordPress.
     *
     * @param array<string, mixed> $payload Payload del post (misma estructura que createPost)
     * @return array<string, mixed> Respuesta con el ID del post creado
     * @throws RuntimeException Si falla la publicación
     */
    public function createPostViaBridge(array $payload): array
    {
        $this->stats['requests']++;

        $bridgeUrl = rtrim($this->baseUrl, '/') . '/wp-media-bridge.php?action=create_post';

        $ch = curl_init($bridgeUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                $this->authHeader,
                'Content-Type: application/json',
                'Cache-Control: no-cache',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_FRESH_CONNECT  => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->stats['errors']++;
            $errorMsg = $error ?: "HTTP {$httpCode}";
            $detail = '';
            if ($response) {
                $decoded = json_decode($response, true);
                if (is_array($decoded)) {
                    if (isset($decoded['message'])) {
                        $detail = ' - ' . $decoded['message'];
                    } elseif (isset($decoded['error'])) {
                        $detail = ' - ' . $decoded['error'];
                    }
                } else {
                    $detail = ' - ' . substr($response, 0, 300);
                }
            }
            throw new RuntimeException(
                "Error creando post vía bridge: {$errorMsg}{$detail}"
            );
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['id'])) {
            $this->stats['errors']++;
            throw new RuntimeException(
                "Respuesta inesperada al crear post vía bridge: no se recibió ID"
            );
        }

        $this->stats['success']++;
        $this->stats['posts_created']++;

        return $decoded;
    }

    /**
     * Actualiza meta fields de un post existente usando el bridge script de WordPress.
     *
     * El plugin ACF Photo Gallery Field (navz.me) guarda el campo image_comic
     * a través de $_POST['acf-photo-gallery-groups'] en su hook save_post.
     * Esto no funciona vía REST API, por lo que usamos un bridge script
     * (wp-update-meta.php) dentro del propio WordPress que llama directamente
     * a update_post_meta().
     *
     * @param int   $postId ID del post en WordPress
     * @param array<string, mixed> $metaFields Mapa clave→valor de meta fields
     *                                         Ej: ['image_comic' => 'id1,id2', '_image_comic' => 'field_69e4761779913']
     * @return array<string, mixed> Respuesta del bridge
     * @throws RuntimeException Si falla la actualización
     */
    public function updatePostMeta(int $postId, array $metaFields): array
    {
        $this->stats['requests']++;

        $bridgeUrl = rtrim($this->baseUrl, '/') . '/wp-update-meta.php';
        $payload   = [
            'post_id' => $postId,
            'meta'    => $metaFields,
        ];

        $ch = curl_init($bridgeUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                $this->authHeader,
                'Content-Type: application/json',
                'Cache-Control: no-cache',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_FRESH_CONNECT  => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->stats['errors']++;
            $errorMsg = $error ?: "HTTP {$httpCode}";
            $detail = '';
            if ($response) {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && isset($decoded['message'])) {
                    $detail = ' - ' . $decoded['message'];
                }
            }
            throw new RuntimeException(
                "Error actualizando meta del post {$postId}: {$errorMsg}{$detail}"
            );
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->stats['errors']++;
            throw new RuntimeException(
                "Respuesta no JSON al actualizar meta del post {$postId}"
            );
        }

        $this->stats['success']++;
        return $decoded;
    }

    /**
     * Obtiene las estadísticas acumuladas de la sesión.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    // ─────────────────────────────────────────────────────────────
    //  MÉTODOS PRIVADOS
    // ─────────────────────────────────────────────────────────────

    /**
     * Hace cumplir el rate limiter global: pausa si la última petición fue muy reciente.
     */
    private static function enforceRateLimit(): void
    {
        if (!defined('PUBLISH_RATE_LIMIT_SECONDS')) {
            return;
        }
        $rateLimit = (float) PUBLISH_RATE_LIMIT_SECONDS;
        if ($rateLimit <= 0) {
            return;
        }
        $now = microtime(true);
        $elapsed = $now - self::$lastRequestTime;
        if ($elapsed < $rateLimit && self::$lastRequestTime > 0) {
            $waitUs = (int) (($rateLimit - $elapsed) * 1000000);
            usleep($waitUs);
        }
        self::$lastRequestTime = microtime(true);
    }

    /**
     * Espera si el SSL meltdown cooldown está activo.
     *
     * Se llama al inicio de cada uploadImage(). Si el stack SSL del
     * servidor colapsó (errno 35 en handle fresco), este método bloquea
     * hasta que el cooldown expire, permitiendo que el servidor se recupere.
     */
    private static function waitForSslMeltdownCooldown(): void
    {
        if (self::$sslMeltdownUntil <= 0) {
            return;
        }

        $now = microtime(true);
        if ($now >= self::$sslMeltdownUntil) {
            // Cooldown expirado, resetear
            self::$sslMeltdownUntil = 0.0;
            return;
        }

        $remaining = (int) ceil(self::$sslMeltdownUntil - $now);
        error_log("[ComicScraperPro] SSL Meltdown Cooldown activo: esperando {$remaining}s más...");
        sleep($remaining);
        self::$sslMeltdownUntil = 0.0;
    }

    /**
     * Escribe un mensaje en el log del sistema.
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        @file_put_contents(
            $logDir . '/wp_publisher.log',
            "[{$timestamp}] {$message}" . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function request(string $method, string $endpoint, array $queryParams = [], ?array $data = null): array
    {
        // ── Global Rate Limiter ──
        self::enforceRateLimit();

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $this->stats['requests']++;

        $ch = curl_init($url);
        $headers = [
            $this->authHeader,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_FRESH_CONNECT  => true,
        ];

        if ($data !== null) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $encoded;
            } elseif ($method === 'PUT') {
                $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $options[CURLOPT_POSTFIELDS] = $encoded;
            }
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->stats['errors']++;
            $errorMsg = $error ?: "HTTP {$httpCode}";
            throw new RuntimeException(
                "Error en petición {$method} {$endpoint}: {$errorMsg}" .
                ($response ? ' - ' . substr($response, 0, 300) : '')
            );
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->stats['errors']++;
            throw new RuntimeException(
                "Respuesta no JSON en {$method} {$endpoint}"
            );
        }

        $this->stats['success']++;
        return $decoded;
    }

    /**
     * Detecta el MIME type de un archivo de imagen.
     *
     * @param string $filePath
     * @return string
     */
    private function detectMimeType(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
        ];

        if (isset($map[$ext])) {
            return $map[$ext];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        return $mime ?: 'application/octet-stream';
    }

    /**
     * Sanitiza un string para usarlo como slug de WordPress.
     *
     * @param string $text
     * @return string
     */
    private function sanitizeSlug(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[()]/u', '', $text);
        $text = preg_replace('/[^a-z0-9áéíóúüñ\-.]+/u', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }

    /**
     * Traduce el slug de taxonomía al segmento de endpoint REST API de WordPress.
     *
     * WordPress usa endpoints REST especiales para taxonomías nativas:
     *   - post_tag → tags      (NO post_tag)
     *   - category → categories (NO category)
     * Las taxonomías personalizadas usan su propio slug.
     *
     * @param string $taxonomy Slug interno de la taxonomía (ej: 'post_tag', 'universo')
     * @return string Segmento del endpoint REST (ej: 'tags', 'universo')
     */
    private function getTaxonomyEndpoint(string $taxonomy): string
    {
        $map = [
            'post_tag' => 'tags',
            'category' => 'categories',
        ];

        return $map[$taxonomy] ?? $taxonomy;
    }

    /**
     * Escribe un registro detallado de diagnóstico en debug_upload.log
     * cuando una petición cURL de subida falla (HTTP != 200/201 o error cURL).
     *
     * Captura: timestamp, ID del cómic, nombre del archivo, HTTP code,
     * cURL errno, cURL error message, y primeros 500 caracteres del body
     * de respuesta del servidor (para ver errores 502/503, bloqueos WAF, etc.)
     *
     * @param string $fileName   Nombre del archivo que se intentaba subir
     * @param int    $httpCode   Código HTTP devuelto (0 si error cURL)
     * @param int    $curlErrno  Número de error cURL (0 si no hubo error)
     * @param string $curlError  Mensaje de error cURL
     * @param string|false $responseBody Cuerpo de la respuesta del servidor (false si no hubo)
     * @param string $context    Contexto adicional (ej: "keep-alive persistent handle falló", "handle fresco")
     * @param int    $imageNum   Número de esta imagen dentro del cómic (0 = desconocido)
     * @param int    $totalImages Total de imágenes del cómic (0 = desconocido)
     */
    private function writeDebugUploadLog(
        string $fileName,
        int $httpCode,
        int $curlErrno,
        string $curlError,
        $responseBody,
        string $context = '',
        int $imageNum = 0,
        int $totalImages = 0
    ): void {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/debug_upload.log';

        $timestamp = date('Y-m-d H:i:s');

        // ── Cabecera ──
        $lines = [];
        $lines[] = '---------------------------------------';
        $lines[] = "[{$timestamp}]";
        if ($this->comicIdentifier !== '') {
            $lines[] = "Cómic: {$this->comicIdentifier}";
        }
        if ($imageNum > 0 && $totalImages > 0) {
            $lines[] = "Archivo: {$fileName} (imagen {$imageNum} de {$totalImages})";
        } else {
            $lines[] = "Archivo: {$fileName}";
        }

        // ── Diagnóstico de Red ──
        $lines[] = "──────────────────────────────────────";
        $lines[] = "HTTP Code (servidor): " . ($httpCode > 0 ? (string) $httpCode : '0 (error cURL, no hubo respuesta HTTP)');
        $lines[] = "cURL errno: {$curlErrno}";
        $lines[] = "cURL error: " . ($curlError !== '' ? $curlError : '(ninguno)');
        if ($context !== '') {
            $lines[] = "Contexto: {$context}";
        }

        // ── Respuesta del Servidor (primeros 500 chars) ──
        $lines[] = "──────────────────────────────────────";
        if ($responseBody === false || $responseBody === null) {
            $lines[] = "Server Response Body: [vacío / no disponible]";
        } elseif ($responseBody === '') {
            $lines[] = "Server Response Body: [cadena vacía]";
        } else {
            $bodyPreview = substr($responseBody, 0, 500);
            // Intentar formatear si es JSON
            $decoded = json_decode($bodyPreview, true);
            if (is_array($decoded)) {
                $lines[] = "Server Response Body (JSON decodificado, máx 500 chars):";
                $lines[] = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                $lines[] = "Server Response Body (primeros 500 chars):";
                $lines[] = $bodyPreview;
                if (strlen($responseBody) > 500) {
                    $lines[] = '... [truncado, ' . strlen($responseBody) . ' bytes totales]';
                }
            }
        }
        $lines[] = '---------------------------------------';
        $lines[] = ''; // línea en blanco separadora

        $entry = implode(PHP_EOL, $lines);
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
