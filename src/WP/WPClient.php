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
     * @param string $filePath Ruta absoluta del archivo en disco
     * @param string $fileName Nombre del archivo (slug)
     * @param string|null $altText Texto alternativo
     * @return array<string, mixed> Respuesta con el ID del attachment y URL
     * @throws RuntimeException Si falla la subida
     */
    public function uploadImage(string $filePath, string $fileName, ?string $altText = null): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("Archivo no encontrado o no legible: {$filePath}");
        }

        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            throw new RuntimeException("No se pudo leer el archivo: {$filePath}");
        }

        $mimeType = $this->detectMimeType($filePath);
        $endpoint = '/wp-json/wp/v2/media';

        $this->stats['requests']++;

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                $this->authHeader,
                'Content-Type: ' . $mimeType,
                'Content-Disposition: attachment; filename="' . $fileName . '"',
                'Cache-Control: no-cache',
            ],
            CURLOPT_POSTFIELDS     => $fileData,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->stats['errors']++;
            $errorMsg = $error ?: "HTTP {$httpCode}";
            throw new RuntimeException(
                "Error subiendo imagen {$fileName}: {$errorMsg}" .
                ($response ? ' - ' . substr($response, 0, 200) : '')
            );
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['id'])) {
            $this->stats['errors']++;
            throw new RuntimeException(
                "Respuesta inesperada al subir {$fileName}: no se recibió ID"
            );
        }

        $this->stats['success']++;
        $this->stats['images_uploaded']++;

        return $decoded;
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
     * Realiza una petición HTTP genérica a la REST API.
     *
     * @param string $method GET | POST
     * @param string $endpoint
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    private function request(string $method, string $endpoint, array $queryParams = [], ?array $data = null): array
    {
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
}
