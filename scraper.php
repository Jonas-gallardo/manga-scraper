<?php
/**
 * scraper.php
 *
 * Motor principal de scraping — VERSIÓN MEJORADA.
 *
 * MEJORAS:
 *   ✓ DOMDocument + DOMXPath en lugar de regex (más robusto)
 *   ✓ Paginación en modo batch (página inicial + cantidad de cómics)
 *   ✓ Reanudación de descargas interrumpidas
 *   ✓ Detección mejorada de duplicados (BD + disco)
 *   ✓ Extracción de metadatos (autor, artista, tags, sinopsis)
 *   ✓ Logging a base de datos
 *   ✓ Control de progreso batch en BD
 *   ✓ Configuración centralizada
 *
 * MODOS:
 *   action=single         → Cómic individual  (/view/ID)
 *   action=batch          → Universo completo (/parody/...) con control de rango
 *
 * PARÁMETROS ADICIONALES (modo batch):
 *   start_page   → Número de página del listado por donde empezar (default: 1)
 *   max_comics   → Máximo de cómics a descargar (default: 50)
 *   per_page     → Enlaces a extraer por página del listado (default: 50)
 */

// ──────────────────────────────────────────────────────────────
// 0. CONFIGURACIÓN INICIAL
// ──────────────────────────────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(0);
ignore_user_abort(false);

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_implicit_flush(true);

header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/includes/TaxonomyProcessor.php';

// ── Instancia global del procesador de taxonomías ──
$taxProcessor = new TaxonomyProcessor();

// ──────────────────────────────────────────────────────────────
// 1. FUNCIONES AUXILIARES
// ──────────────────────────────────────────────────────────────

/**
 * Envía una línea JSON al cliente y fuerza el flush.
 */
function send_progress(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

/**
 * Registra un evento en la tabla log_descargas.
 */
function log_to_db(PDO $pdo, ?int $id_fuente, string $tipo, string $mensaje): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO log_descargas (id_fuente, tipo, mensaje) VALUES (?, ?, ?)'
        );
        $stmt->execute([$id_fuente, $tipo, $mensaje]);
    } catch (Exception $e) {
        // Si falla el logging, no interrumpimos el proceso
    }
}

/**
 * Escribe en archivo de log rotativo.
 */
function log_to_file(string $message): void {
    $dir = LOG_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    // Si el directorio existe pero no es escribible, corregir permisos
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0777);
    }
    $file = LOG_FILE;
    // Rotar si excede el tamaño máximo
    if (file_exists($file) && filesize($file) > LOG_MAX_SIZE) {
        $backup = $file . '.' . date('Ymd-His');
        @rename($file, $backup);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Configura un recurso cURL con cabeceras de camuflaje.
 */
function crear_curl(string $url): CurlHandle {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => CURL_MAXREDIRS,
        CURLOPT_TIMEOUT         => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT  => CURL_CONNECT_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER  => CURL_SSL_VERIFY,
        CURLOPT_SSL_VERIFYHOST  => CURL_SSL_VERIFY ? 2 : 0,
        CURLOPT_USERAGENT       => USER_AGENT,
        CURLOPT_HTTPHEADER      => unserialize(HTTP_HEADERS),
        CURLOPT_ENCODING        => '',
    ]);
    return $ch;
}

/**
 * Extrae el ID numérico de una URL usando el path configurado (ej: /d/ID o /view/ID).
 * También soporta URLs con solo el ID numérico al final (ej: /676046).
 */
function extraer_id(string $url): ?int {
    // 1 — Intentar con el path configurado (ej: /d/ID o /view/ID)
    $escaped_path = preg_quote(SITE_VIEW_PATH, '#');
    if (preg_match('#' . $escaped_path . '/(\d+)#', $url, $m)) {
        return (int) $m[1];
    }
    // 2 — Intentar con URL completa tipo https://dominio/ID (sin path)
    if (preg_match('#https?://[^/]+/(\d+)(?:/|$)#', $url, $m)) {
        return (int) $m[1];
    }
    // 3 — Fallback: cualquier ruta que termine en /ID o /ID/ (con o sin extensión)
    if (preg_match('#/(\d+)/?(?:\?.*)?$#', $url, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * Extrae el nombre del universo desde /parody/... o /search?q=...
 */
function extraer_universo(string $url): ?string {
    $escaped_batch = preg_quote(SITE_BATCH_PATH, '~');
    // Intentar con el path batch configurado (ej: /parody/NOMBRE o /search)
    if (preg_match('~' . $escaped_batch . '/([^/?#]+)~', $url, $m)) {
        return urldecode(str_replace(['-', '_'], ' ', $m[1]));
    }
    // Si es /search?q=... extraer el query como nombre del universo
    if (preg_match('~' . $escaped_batch . '\?q=([^&#]+)~', $url, $m)) {
        return urldecode(str_replace(['-', '_', '+'], ' ', $m[1]));
    }
    // Fallback genérico para search query
    if (preg_match('~[?&]q=([^&#]+)~', $url, $m)) {
        return urldecode(str_replace(['-', '_', '+'], ' ', $m[1]));
    }
    return null;
}

/**
 * Obtiene el HTML de una página con reintentos ante errores.
 */
function obtener_html(string $url, int &$retries = 0): ?string {
    $max_retries = MAX_RETRIES;
    $ch = crear_curl($url);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($html === false || in_array($http_code, [403, 429, 503], true)) {
        $retries++;
        if ($retries <= $max_retries) {
            $reason = $html === false ? "cURL error: $error" : "HTTP $http_code";
            send_progress([
                'type'    => 'warning',
                'message' => "Error ($reason) — Reintento $retries/$max_retries en " . RETRY_WAIT_SECONDS . " s..."
            ]);
            sleep(RETRY_WAIT_SECONDS);
            return obtener_html($url, $retries);
        }
        send_progress([
            'type'    => 'error',
            'message' => "Fallo definitivo tras $max_retries reintentos ($url)"
        ]);
        return null;
    }

    if ($http_code === 404) {
        send_progress([
            'type'    => 'warning',
            'message' => "Página no encontrada (HTTP 404): $url"
        ]);
        return null;
    }

    return $html;
}

/**
 * Obtiene el HTML de una página del listado de universo con paginación.
 */
function obtener_html_paginado(string $base_url, int $page): ?string {
    $sep = (strpos($base_url, '?') === false) ? '?' : '&';
    $url = $base_url . $sep . BATCH_PAGE_PARAM . '=' . $page;
    send_progress([
        'type'    => 'info',
        'message' => "📄 Obteniendo página $page del listado..."
    ]);
    return obtener_html($url);
}

/**
 * Extrae el contenido de una meta tag por propiedad o nombre.
 */
function extraer_meta(DOMXPath $xpath, string $property): ?string {
    // Por property (og:title, etc.)
    $nodes = $xpath->query("//meta[@property='$property']");
    if ($nodes && $nodes->length > 0) {
        $content = $nodes->item(0)->getAttribute('content');
        if ($content) return trim($content);
    }
    // Por name
    $nodes = $xpath->query("//meta[@name='$property']");
    if ($nodes && $nodes->length > 0) {
        $content = $nodes->item(0)->getAttribute('content');
        if ($content) return trim($content);
    }
    return null;
}

/**
 * Extrae el texto de un elemento por selector XPath.
 */
function extraer_texto_xpath(DOMXPath $xpath, string $query): ?string {
    $nodes = $xpath->query($query);
    if ($nodes && $nodes->length > 0) {
        return trim($nodes->item(0)->textContent);
    }
    return null;
}

/**
 * Parsea HTML con DOMDocument y devuelve un DOMXPath.
 */
function crear_xpath(string $html): ?DOMXPath {
    $dom = new DOMDocument();
    // Suprimir warnings por HTML malformado
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

/**
 * Extrae el título del cómic usando DOMXPath.
 */
function extraer_titulo(DOMXPath $xpath, string $html): string {
    // 1 — og:title
    $og = extraer_meta($xpath, 'og:title');
    if ($og) return $og;

    // 2 — <h1> con clase que contenga "title"
    $h1 = extraer_texto_xpath($xpath, "//h1[contains(@class, 'title')]");
    if ($h1) return $h1;

    // 3 — <title>
    $title = extraer_texto_xpath($xpath, '//title');
    if ($title) {
        $title = preg_replace('#\s*[|-]\s*[^-|]+$#', '', $title);
        return trim($title);
    }

    // 4 — Cualquier <h1>
    $h1 = extraer_texto_xpath($xpath, '//h1');
    if ($h1) return $h1;

    return 'Título desconocido';
}

/**
 * Extrae el número TOTAL de páginas del cómic usando DOMXPath.
 */
function extraer_total_paginas(DOMXPath $xpath, string $html): int {
    $escaped_view = preg_quote(SITE_VIEW_PATH, '#');

    // 1 — Texto "Pages: N" o "Páginas: N"
    if (preg_match('#(?:Pages?|Páginas?)\s*:?\s*(\d+)#i', $html, $m)) {
        return max(1, (int) $m[1]);
    }
    // 2 — "of N" / "de N" en paginación
    if (preg_match('#(?:page|pág\.?)\s*\d+\s*(?:of|de|/)\s*(\d+)#i', $html, $m)) {
        return max(1, (int) $m[1]);
    }
    // 3 — data-total-pages
    $nodes = $xpath->query("//*[@data-total-pages or @data-total]");
    if ($nodes && $nodes->length > 0) {
        $val = $nodes->item(0)->getAttribute('data-total-pages') ?: $nodes->item(0)->getAttribute('data-total');
        if ($val && is_numeric($val)) return max(1, (int) $val);
    }
    // 4 — Extraer todos los números de SITE_VIEW_PATH/ID/N y tomar el máximo
    if (preg_match_all('#' . $escaped_view . '/\d+/(\d+)#', $html, $m)) {
        $nums = array_map('intval', $m[1]);
        if (!empty($nums)) return max(1, max($nums));
    }
    // 5 — Clase con total-pages
    $txt = extraer_texto_xpath($xpath, "//*[contains(@class, 'total-pages') or contains(@class, 'page-count') or contains(@class, 'num-pages')]");
    if ($txt && is_numeric($txt)) return max(1, (int) $txt);

    return 20;
}

/**
 * Extrae autor del cómic.
 */
function extraer_autor(DOMXPath $xpath, string $html): ?string {
    // 1 — meta name="author"
    $author = extraer_meta($xpath, 'author');
    if ($author) return $author;
    // 2 — og:author
    $author = extraer_meta($xpath, 'og:author');
    if ($author) return $author;
    // 3 — Elemento con clase "author" o "writer"
    $txt = extraer_texto_xpath($xpath, "//*[contains(@class, 'author') or contains(@class, 'writer')]//a | //*[contains(@class, 'author') or contains(@class, 'writer')]");
    if ($txt) return $txt;
    return null;
}

/**
 * Extrae valores de una sección específica dentro de div.tag-container.field-name.
 * Identifica la sección por su label de texto (ej. "Series:", "Personajes:", etc.).
 *
 * @param DOMXPath $xpath
 * @param string $sectionLabel Label exacto de la sección (ej. "Series:", "Etiquetas:")
 * @return array<string> Valores encontrados dentro de <a class="name">
 */
function extraer_valores_seccion(DOMXPath $xpath, string $sectionLabel): array {
    $result = [];
    $containers = $xpath->query("//div[contains(@class, 'tag-container') and contains(@class, 'field-name')]");
    foreach ($containers as $container) {
        $firstText = trim($container->textContent);
        if (strpos($firstText, $sectionLabel) === 0) {
            $links = $xpath->query(".//a[contains(@class, 'name')]", $container);
            foreach ($links as $link) {
                $val = trim($link->textContent);
                if ($val !== '') {
                    $result[] = $val;
                }
            }
            break;
        }
    }
    return $result;
}

/**
 * Extrae tags/etiquetas del cómic — AHORA solo extrae de la sección "Etiquetas:".
 */
function extraer_tags(DOMXPath $xpath, string $html): ?string {
    // 1 — meta name="keywords"
    $kw = extraer_meta($xpath, 'keywords');
    if ($kw) return $kw;
    // 2 — Extraer solo de la sección "Etiquetas:" del HTML estructurado
    $tags = extraer_valores_seccion($xpath, 'Etiquetas:');
    if (!empty($tags)) return implode(', ', array_unique($tags));
    return null;
}

/**
 * Extrae la serie/universo del cómic desde la sección "Series:".
 *
 * @param DOMXPath $xpath
 * @return string|null Nombre(s) de serie separados por coma, o null
 */
function extraer_series(DOMXPath $xpath): ?string {
    $values = extraer_valores_seccion($xpath, 'Series:');
    return !empty($values) ? implode(', ', array_unique($values)) : null;
}

/**
 * Extrae los personajes del cómic desde la sección "Personajes:".
 *
 * @param DOMXPath $xpath
 * @return string|null Nombres de personajes separados por coma, o null
 */
function extraer_personajes(DOMXPath $xpath): ?string {
    $values = extraer_valores_seccion($xpath, 'Personajes:');
    return !empty($values) ? implode(', ', array_unique($values)) : null;
}

/**
 * Extrae la categoría del cómic desde la sección "Categorías:".
 *
 * @param DOMXPath $xpath
 * @return string|null Nombre(s) de categoría separados por coma, o null
 */
function extraer_categorias(DOMXPath $xpath): ?string {
    $values = extraer_valores_seccion($xpath, 'Categorías:');
    return !empty($values) ? implode(', ', array_unique($values)) : null;
}

/**
 * Extrae sinopsis/descripción del cómic.
 */
function extraer_sinopsis(DOMXPath $xpath, string $html): ?string {
    // 1 — meta name="description"
    $desc = extraer_meta($xpath, 'description');
    if ($desc) return $desc;
    // 2 — og:description
    $desc = extraer_meta($xpath, 'og:description');
    if ($desc) return $desc;
    // 3 — Elemento con clase "description" o "summary"
    $txt = extraer_texto_xpath($xpath, "//*[contains(@class, 'description') or contains(@class, 'summary') or contains(@class, 'sinopsis')]");
    if ($txt) return $txt;
    return null;
}

/**
 * Extrae idioma del cómic.
 * AHORA también verifica la sección "Idiomas:" del HTML estructurado.
 */
function extraer_idioma(DOMXPath $xpath, string $html): ?string {
    // 1 — <html lang="...">
    $nodes = $xpath->query('//html/@lang');
    if ($nodes && $nodes->length > 0) {
        $lang = trim($nodes->item(0)->value);
        if ($lang !== '') return $lang;
    }
    // 2 — meta name="language"
    $lang = extraer_meta($xpath, 'language');
    if ($lang) return $lang;
    // 3 — Extraer de la sección "Idiomas:" filtrando "translated"
    $idiomas = extraer_valores_seccion($xpath, 'Idiomas:');
    if (!empty($idiomas)) {
        $realLang = array_filter($idiomas, function($v) {
            return mb_strtolower(trim($v), 'UTF-8') !== 'translated';
        });
        if (!empty($realLang)) {
            return implode(', ', array_unique($realLang));
        }
    }
    return null;
}

/**
 * Verifica si el usuario solicitó la detención del proceso.
 * Comprueba tanto connection_aborted() como el archivo de señal de stop.
 */
function check_stop_signal(): bool {
    if (connection_aborted()) {
        return true;
    }
    // Archivo de señal de stop creado por stop_scraper.php o el botón Detener
    if (file_exists(SCRAPER_STOP_FILE)) {
        return true;
    }
    return false;
}

/**
 * Elimina un directorio de cómic incompleto y su registro en BD.
 * Solo se llama cuando el usuario detiene el proceso a mitad de una descarga.
 */
function cleanup_incomplete_comic(PDO $pdo, int $id, string $dir_path): void {
    // Eliminar carpeta del disco
    if ($dir_path && is_dir($dir_path)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dir_path);
        send_progress([
            'type'    => 'warning',
            'message' => "🗑 Carpeta incompleta eliminada: {$dir_path}"
        ]);
    }

    // Eliminar registro de BD si existe (solo si es parcial/error)
    try {
        $stmt = $pdo->prepare('DELETE FROM comics_descargados WHERE id_fuente = ? AND estado != ?');
        $stmt->execute([$id, 'completo']);
        if ($stmt->rowCount() > 0) {
            send_progress([
                'type'    => 'warning',
                'message' => "🗑 Registro de BD eliminado para ID {$id}"
            ]);
        }
    } catch (Exception $e) {
        // Ignorar errores de BD en cleanup
    }
}

/**
 * Extrae rating/calificación.
 */
function extraer_rating(DOMXPath $xpath, string $html): ?float {
    // 1 — meta con rating
    $rating = extraer_meta($xpath, 'rating');
    if ($rating && is_numeric($rating)) return (float) $rating;
    // 2 — Elemento con clase "rating" o "score"
    $txt = extraer_texto_xpath($xpath, "//*[contains(@class, 'rating') or contains(@class, 'score')]");
    if ($txt) {
        if (preg_match('#(\d+(?:\.\d+)?)\s*/\s*\d+#', $txt, $m)) {
            return (float) $m[1];
        }
        if (is_numeric(trim($txt))) return (float) trim($txt);
    }
    return null;
}

/**
 * Descarga una imagen desde $url y la guarda en $path.
 * Si devuelve HTML, extrae la URL de imagen y reintenta.
 * Detecta el formato real de la imagen.
 */
function descargar_imagen(string $url, string $path, int &$retries = 0): bool {
    $max_retries = MAX_RETRIES;
    $ch = crear_curl($url);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || in_array($http_code, [403, 429, 503], true)) {
        $retries++;
        if ($retries <= $max_retries) {
            $reason = $response === false ? "cURL: $error" : "HTTP $http_code";
            send_progress([
                'type'    => 'warning',
                'message' => "Error imagen ($reason), reintento $retries/$max_retries en " . RETRY_WAIT_SECONDS . " s..."
            ]);
            sleep(RETRY_WAIT_SECONDS);
            return descargar_imagen($url, $path, $retries);
        }
        send_progress([
            'type'    => 'error',
            'message' => "Imagen falló tras $max_retries reintentos: $url"
        ]);
        return false;
    }

    if ($http_code === 404) {
        send_progress([
            'type'    => 'warning',
            'message' => "Imagen no encontrada (HTTP 404): $url"
        ]);
        return false;
    }

    $body = substr($response, $header_size);

    // Si recibimos HTML, extraer la primera imagen
    if ($content_type && stripos($content_type, 'text/html') !== false) {
        send_progress([
            'type'    => 'info',
            'message' => "La URL devolvió HTML, extrayendo imagen del DOM..."
        ]);
        $img_url = null;
        if (preg_match('#<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp))["\'][^>]*>#si', $body, $m)) {
            $img_url = $m[1];
        } elseif (preg_match('#<img[^>]+src=["\']([^"\']+)["\'][^>]*>#si', $body, $m)) {
            $img_url = $m[1];
        }
        if ($img_url) {
            if (strpos($img_url, 'http') !== 0) {
                $parsed = parse_url($url);
                $base = $parsed['scheme'] . '://' . $parsed['host'];
                $img_url = $base . ($img_url[0] === '/' ? '' : '/') . $img_url;
            }
            return descargar_imagen($img_url, $path, $retries);
        }
        send_progress([
            'type'    => 'warning',
            'message' => "No se pudo extraer URL de imagen del HTML"
        ]);
        return false;
    }

    // Determinar extensión real por Content-Type
    $ext = 'jpg';
    if ($content_type) {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ];
        foreach ($map as $ct => $e) {
            if (stripos($content_type, $ct) !== false) {
                $ext = $e;
                break;
            }
        }
    }

    // Ajustar extensión del archivo
    $path = preg_replace('/\.\w+$/', '.' . $ext, $path);

    $bytes = file_put_contents($path, $body);
    if ($bytes === false) {
        send_progress([
            'type'    => 'error',
            'message' => "Error al escribir archivo: $path"
        ]);
        return false;
    }

    return true;
}

/**
 * Busca enlaces de cómics en el HTML usando DOMXPath.
 * Usa el path configurado (ej: /d/ID o /view/ID).
 * También detecta enlaces con solo ID numérico (ej: /676046).
 */
function extraer_enlaces_universo_dom(string $html): array {
    $enlaces = [];
    $xpath = crear_xpath($html);
    if (!$xpath) return $enlaces;

    $view_path = SITE_VIEW_PATH; // ej: /d o /view
    $escaped   = preg_quote($view_path, '#');

    // 1 — Buscar todos los enlaces que contengan el path configurado + número
    $nodes = $xpath->query("//a[contains(@href, '$view_path/')]");
    if ($nodes) {
        foreach ($nodes as $node) {
            $href = $node->getAttribute('href');
            if (preg_match('#' . $escaped . '/(\d+)#', $href, $m)) {
                $enlaces[] = SITE_VIEW . '/' . $m[1];
            }
        }
    }

    // 2 — Buscar enlaces con solo ID numérico (ej: href="/676046")
    if (empty($enlaces)) {
        $nodes = $xpath->query("//a[contains(@href, '/')]");
        if ($nodes) {
            foreach ($nodes as $node) {
                $href = $node->getAttribute('href');
                if (preg_match('#^/(\d+)/?(?:\?.*)?$#', $href, $m)) {
                    $enlaces[] = SITE_BASE . '/' . $m[1];
                }
            }
        }
    }

    // 3 — Regex fallback: path configurado
    if (empty($enlaces)) {
        if (preg_match_all('#' . $escaped . '/(\d+)#', $html, $m)) {
            $ids = array_unique($m[1]);
            foreach ($ids as $id) {
                $enlaces[] = SITE_VIEW . '/' . $id;
            }
        }
    }

    // 4 — Regex fallback: cualquier número al final de href
    if (empty($enlaces)) {
        if (preg_match_all('#/(\d+)(?:/|$)#', $html, $m)) {
            $ids = array_unique($m[1]);
            foreach ($ids as $id) {
                $enlaces[] = SITE_BASE . '/' . $id;
            }
        }
    }

    return array_values(array_unique($enlaces));
}

/**
 * Valida que la URL tenga el formato esperado según el path configurado.
 */
function validar_url(string $url, string $tipo): bool {
    $escaped_view  = preg_quote(SITE_VIEW_PATH, '#');
    $escaped_batch = preg_quote(SITE_BATCH_PATH, '#');

    if ($tipo === 'single') {
        return (bool) preg_match('#^https?://[^/]+' . $escaped_view . '/\d+#', $url);
    }
    if ($tipo === 'batch') {
        return (bool) preg_match('#^https?://[^/]+' . $escaped_batch . '#', $url);
    }
    return false;
}

/**
 * Crea el directorio para almacenar las imágenes de un cómic.
 * Retorna la ruta del directorio o null si falla.
 * Corrige permisos automáticamente si el directorio existe pero no es escribible.
 */
function crear_directorio_comic(int $id, string $titulo): ?string {
    $sanitized = preg_replace('#[/:*?"<>|]#', '_', $titulo);
    $sanitized = substr($sanitized, 0, 200); // Limitar longitud
    $dir_name = "[{$id}] {$sanitized}";
    $base_dir = DOWNLOADS_DIR;

    // ── Asegurar que el directorio base exista y sea escribible ──
    if (!is_dir($base_dir)) {
        if (!@mkdir($base_dir, 0777, true)) {
            send_progress([
                'type'    => 'error',
                'message' => "No se pudo crear el directorio base: $base_dir"
            ]);
            return null;
        }
    } elseif (!is_writable($base_dir)) {
        // El directorio existe pero no es escribible → intentar corregir permisos
        @chmod($base_dir, 0777);
        if (!is_writable($base_dir)) {
            send_progress([
                'type'    => 'error',
                'message' => "El directorio base no tiene permisos de escritura: $base_dir"
            ]);
            return null;
        }
    }

    $full_path = $base_dir . '/' . $dir_name;
    if (!is_dir($full_path)) {
        if (!@mkdir($full_path, 0777, true)) {
            send_progress([
                'type'    => 'error',
                'message' => "No se pudo crear el directorio: $full_path"
            ]);
            return null;
        }
    } elseif (!is_writable($full_path)) {
        // El directorio del cómic ya existe pero no es escribible → corregir
        @chmod($full_path, 0777);
    }

    // Forzar permisos 0777 (mkdir respeta umask, así que chmod explícito)
    @chmod($full_path, 0777);

    return $full_path;
}

/**
 * Calcula el tamaño de un directorio recursivamente.
 */
/**
 * Verifica si un cómic está en la lista de eliminados (blacklist permanente).
 * Retorna true si está eliminado (no se debe descargar), false si no.
 */
function verificar_eliminado(PDO $pdo, int $id): bool {
    try {
        $stmt = $pdo->prepare('SELECT id_fuente FROM mangas_eliminados WHERE id_fuente = ?');
        $stmt->execute([$id]);
        return (bool) $stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Verifica si un cómic ya fue descargado (BD + disco).
 * Retorna true si existe (duplicado), false si no.
 */
function verificar_duplicado_completo(PDO $pdo, int $id, string $titulo): bool {
    // 1. Verificar en BD
    $stmt = $pdo->prepare('SELECT id_fuente, estado, ruta_carpeta, total_paginas FROM comics_descargados WHERE id_fuente = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if ($row) {
        // Ya existe en BD. Verificar si también existe en disco.
        if ($row['ruta_carpeta'] && is_dir($row['ruta_carpeta'])) {
            // Contar imágenes reales en disco (usa escanear_imagenes que soporta UTF-8)
            $files = escanear_imagenes($row['ruta_carpeta']);
            $num_files = count($files);

            // ── AUTO-REPARACIÓN: si el estado es 'error' pero hay archivos, corregir ──
            if ($row['estado'] === 'error' && $num_files > 0) {
                $total_esperado = (int) $row['total_paginas'];
                $nuevo_estado = ($num_files >= $total_esperado && $total_esperado > 0) ? 'completo' : 'parcial';

                $stmt_repair = $pdo->prepare(
                    'UPDATE comics_descargados SET estado = ?, paginas_ok = ?, paginas_fail = ? WHERE id_fuente = ?'
                );
                $stmt_repair->execute([$nuevo_estado, $num_files, max(0, $total_esperado - $num_files), $id]);

                send_progress([
                    'type'    => 'info',
                    'message' => "🔧 Cómic ID {$id} corregido: estado '{$row['estado']}' → '{$nuevo_estado}' ({$num_files}/{$total_esperado} páginas en disco)"
                ]);

                if ($nuevo_estado === 'completo') {
                    return true; // Ya está completo
                }
                // Si es 'parcial', permitir reanudar
            }

            if ($num_files > 0 && $num_files >= ($row['total_paginas'] * 0.8)) {
                // Tiene al menos el 80% de las páginas → considerar completo
                return true;
            }

            // Tiene archivos pero incompleto → permitir reanudar
            send_progress([
                'type'    => 'info',
                'message' => "🔄 Cómic ID {$id} encontrado en BD pero incompleto en disco ({$num_files}/{$row['total_paginas']} páginas). Reanudando..."
            ]);
            return false; // Permitir re-descarga (reanudación)
        }

        // Existe en BD pero no en disco → permitir re-descarga
        send_progress([
            'type'    => 'info',
            'message' => "🔄 Cómic ID {$id} registrado en BD pero no encontrado en disco. Re-descargando..."
        ]);
        return false;
    }

    // 2. Verificar en disco (caso de BD borrada o migración)
    $sanitized = preg_replace('#[/:*?"<>|]#', '_', $titulo);
    $sanitized = substr($sanitized, 0, 200);
    $dir_name = "[{$id}] {$sanitized}";
    $candidate_dir = DOWNLOADS_DIR . '/' . $dir_name;

    if (is_dir($candidate_dir)) {
        $files = escanear_imagenes($candidate_dir);
        if (count($files) > 0) {
            send_progress([
                'type'    => 'info',
                'message' => "📂 Cómic ID {$id} encontrado en disco pero no en BD. Registrando..."
            ]);
            return false; // Lo registramos en BD pero permitimos continuar
        }
    }

    return false; // No es duplicado
}

/**
 * Registra o actualiza un cómic en la BD.
 */
function registrar_comic(PDO $pdo, int $id, string $titulo, ?string $universo, ?string $autor,
                         ?string $artista, ?string $tags, ?string $sinopsis, ?string $idioma,
                         ?float $rating, int $total_paginas, int $paginas_ok, int $paginas_fail,
                         string $estado, string $ruta_carpeta, ?string $taxonomias = null): void {
    $tamano = ($ruta_carpeta && is_dir($ruta_carpeta)) ? calcular_tamano_dir($ruta_carpeta) : 0;

    $stmt = $pdo->prepare(
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
    $stmt->execute([$id, $titulo, $universo, $autor, $artista, $tags, $sinopsis,
                    $total_paginas, $paginas_ok, $paginas_fail, $tamano, $idioma, $rating,
                    $estado, $ruta_carpeta, $taxonomias]);
}

/**
 * Pausa con sleep aleatorio entre min y max.
 */
function delay(float $min, float $max): void {
    $sleep = mt_rand((int)($min * 100), (int)($max * 100)) / 100;
    send_progress([
        'type'    => 'wait',
        'message' => "⏳ Esperando {$sleep} s..."
    ]);
    usleep((int) ($sleep * 1_000_000));
}

/**
 * Obtiene la última página procesada para una URL de batch desde el historial.
 * Retorna 0 si no hay historial (empezar desde página 1).
 */
function obtener_ultima_pagina_historial(PDO $pdo, string $url): int {
    try {
        $stmt = $pdo->prepare(
            'SELECT ultima_pagina FROM batch_historial WHERE url_base = ?'
        );
        $stmt->execute([$url]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['ultima_pagina'];
        }
    } catch (Exception $e) {
        // Si falla la consulta, ignoramos
    }
    return 0;
}

/**
 * Guarda o actualiza el historial de procesamiento de una URL de batch.
 */
function guardar_historial_batch(PDO $pdo, string $url, string $universo,
                                  int $pagina_inicial, int $ultima_pagina,
                                  int $max_comics, int $total_enlaces,
                                  int $comics_descargados, int $comics_omitidos,
                                  int $comics_errores, bool $completado): void {
    try {
        $stmt = $pdo->prepare(
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
        $stmt->execute([$url, $universo, $ultima_pagina, $pagina_inicial, $max_comics,
                        $total_enlaces, $comics_descargados, $comics_omitidos, $comics_errores,
                        $completado ? 1 : 0]);
    } catch (Exception $e) {
        // Si falla el historial, no interrumpimos
        log_to_file("Error guardando historial batch: " . $e->getMessage());
    }
}

/**
 * Actualiza solo la última página en el historial (llamada tras cada página procesada).
 */
function actualizar_ultima_pagina_historial(PDO $pdo, string $url, int $pagina): void {
    try {
        $stmt = $pdo->prepare(
            'UPDATE batch_historial SET ultima_pagina = ?, fecha_ejecucion = NOW() WHERE url_base = ?'
        );
        $stmt->execute([$pagina, $url]);
    } catch (Exception $e) {
        // Ignoramos
    }
}


// ──────────────────────────────────────────────────────────────
// 2. PROCESAMIENTO DE LA PETICIÓN
// ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_progress(['type' => 'error', 'message' => 'Solo se aceptan peticiones POST']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$url    = trim($_POST['url'] ?? '');

// Parámetros adicionales para batch
$start_page = max(1, (int) ($_POST['start_page'] ?? BATCH_DEFAULT_PAGE));
$max_comics = max(1, (int) ($_POST['max_comics'] ?? BATCH_DEFAULT_MAX));

if (empty($url)) {
    send_progress(['type' => 'error', 'message' => 'La URL es obligatoria']);
    exit;
}

if (!in_array($action, ['single', 'batch'], true)) {
    send_progress(['type' => 'error', 'message' => 'Acción no válida (use single o batch)']);
    exit;
}

if (!validar_url($url, $action)) {
    send_progress([
        'type'    => 'error',
        'message' => "La URL no coincide con el formato esperado para el modo " . strtoupper($action)
    ]);
    exit;
}

// Log inicial
log_to_file("INICIO - Modo: $action - URL: $url" . ($action === 'batch' ? " (start_page=$start_page, max=$max_comics)" : ''));

// ── Limpiar señal de stop residual de ejecuciones anteriores ──
@unlink(SCRAPER_STOP_FILE);


// ════════════════════════════════════════════════════════════════
//  MODO A — CÓMIC INDIVIDUAL (MEJORADO)
// ════════════════════════════════════════════════════════════════

if ($action === 'single') {
    $id = extraer_id($url);

    if ($id === null) {
        send_progress(['type' => 'error', 'message' => 'No se pudo extraer el ID del cómic de la URL']);
        exit;
    }

    // ── 0. Verificar blacklist (mangas eliminados por el usuario) ──
    if (verificar_eliminado($pdo, $id)) {
        send_progress([
            'type'    => 'warning',
            'message' => "⛔ El cómic ID {$id} está en la lista de eliminados. No se descargará."
        ]);
        log_to_db($pdo, $id, 'warning', "Intento de descarga de manga eliminado (ID {$id})");
        exit;
    }

    // ── 1. Verificar duplicado (mejorado) ──
    $stmt = $pdo->prepare('SELECT id_fuente, estado, ruta_carpeta, total_paginas, paginas_ok FROM comics_descargados WHERE id_fuente = ?');
    $stmt->execute([$id]);
    $existente = $stmt->fetch();

    $reanudar = false;
    if ($existente) {
        if ($existente['estado'] === 'completo') {
            send_progress([
                'type'    => 'error',
                'message' => "⚠️  El cómic ID {$id} («{$existente['titulo']}») YA EXISTE y está completo. Descarga omitida."
            ]);
            log_to_db($pdo, $id, 'warning', "Intento de descarga duplicada (completo)");
            exit;
        }

        // Estado parcial o error → permitir reanudar
        $reanudar = true;
        $pag_inicial = ($existente['paginas_ok'] ?? 0) + 1;
        $ruta_existente = $existente['ruta_carpeta'];

        send_progress([
            'type'    => 'info',
            'message' => "🔄 Reanudando descarga del cómic ID {$id} desde página {$pag_inicial} (estado: {$existente['estado']})"
        ]);

        // Actualizar estado a 'descargando'
        $stmt = $pdo->prepare("UPDATE comics_descargados SET estado = 'descargando' WHERE id_fuente = ?");
        $stmt->execute([$id]);
    }

    // ── 2. Obtener HTML ──
    send_progress([
        'type'    => 'info',
        'message' => "📄 Obteniendo página del cómic: {$url}"
    ]);
    $html = obtener_html($url);
    if ($html === null) {
        send_progress(['type' => 'error', 'message' => "No se pudo obtener la página del cómic {$id}"]);
        log_to_db($pdo, $id, 'error', "Fallo al obtener HTML");
        exit;
    }

    $xpath = crear_xpath($html);
    if (!$xpath) {
        send_progress(['type' => 'error', 'message' => 'Error al parsear el HTML']);
        exit;
    }

    $titulo       = extraer_titulo($xpath, $html);
    $total_paginas = extraer_total_paginas($xpath, $html);
    $autor        = extraer_autor($xpath, $html);
    $tags         = extraer_tags($xpath, $html);             // "Etiquetas:" section → tags
    $series       = extraer_series($xpath);                  // "Series:" section → universos
    $personajes   = extraer_personajes($xpath);              // "Personajes:" section → personajes
    $categorias   = extraer_categorias($xpath);              // "Categorías:" section → tipos
    $sinopsis     = extraer_sinopsis($xpath, $html);
    $idioma       = extraer_idioma($xpath, $html);
    $rating       = extraer_rating($xpath, $html);

    // Nota: artista no se extrae directamente, usamos autor como fallback
    $artista = null;

    // ── Procesar taxonomías ──
    $taxData = $taxProcessor->processFromScraper([
        'tags'       => $tags,
        'universo'   => $series,     // "Series:" → universo
        'idioma'     => $idioma,
        'autor'      => $autor,
        'tipo'       => $categorias, // "Categorías:" → tipo
        'personajes' => $personajes, // "Personajes:" → personajes
    ]);
    $taxonomiasJson = json_encode($taxData, JSON_UNESCAPED_UNICODE);

    send_progress([
        'type'    => 'info',
        'message' => "🏷️  Título: {$titulo}  |  📖 Páginas: {$total_paginas}" .
                     ($autor ? "  |  ✍️ Autor: {$autor}" : "") .
                     "  |  🏷️ Etiquetas: " . (count($taxData['etiquetas']) ? implode(', ', $taxData['etiquetas']) : 'ninguna')
    ]);

    // ── 3. Crear / verificar directorio ──
    if ($reanudar && !empty($ruta_existente) && is_dir($ruta_existente)) {
        $dir_path = $ruta_existente;
        send_progress([
            'type'    => 'info',
            'message' => "📁 Usando carpeta existente: {$dir_path}"
        ]);
    } else {
        $dir_path = crear_directorio_comic($id, $titulo);
        if ($dir_path === null) {
            send_progress(['type' => 'error', 'message' => "No se pudo crear el directorio"]);
            exit;
        }
        $reanudar = false;
        $pag_inicial = 1;
    }

    // ── 4. Descargar cada página ──
    $paginas_ok   = $reanudar ? ($existente['paginas_ok'] ?? 0) : 0;
    $paginas_fail = $reanudar ? ($existente['paginas_fail'] ?? 0) : 0;

    for ($pag = ($reanudar ? $pag_inicial : 1); $pag <= $total_paginas; $pag++) {
        // Saltar si el archivo ya existe (reanudación)
        $filename = str_pad($pag, 3, '0', STR_PAD_LEFT);
        // Verificar si el archivo ya existe (scandir directo, compatible con UTF-8)
        $existing = [];
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
            $test_path = $dir_path . '/' . $filename . '.' . $ext;
            if (is_file($test_path)) {
                $existing[] = $test_path;
                break;
            }
        }
        if (!empty($existing)) {
            $paginas_ok++;
            send_progress([
                'type'    => 'success',
                'message' => "✅ Página {$pag} ya existe, saltando..."
            ]);
            continue;
        }

        $img_url  = SITE_VIEW . "/{$id}/{$pag}";
        $filepath = $dir_path . '/' . $filename . '.jpg';

        send_progress([
            'type'    => 'progress',
            'current' => $pag,
            'total'   => $total_paginas,
            'message' => "⬇️  Descargando página {$pag}/{$total_paginas}..."
        ]);

        $retries = 0;
        $ok = descargar_imagen($img_url, $filepath, $retries);

        if ($ok) {
            $paginas_ok++;
            send_progress([
                'type'    => 'success',
                'message' => "✅ Página {$pag} guardada"
            ]);
        } else {
            $paginas_fail++;
            send_progress([
                'type'    => 'warning',
                'message' => "⚠️  Página {$pag} omitida tras fallos"
            ]);
        }

        // ── Check stop signal after each page download ──
        if (check_stop_signal()) {
            send_progress([
                'type'    => 'warning',
                'message' => "⏹  Señal de detención recibida. Limpiando descarga incompleta..."
            ]);
            cleanup_incomplete_comic($pdo, $id, $dir_path);
            log_to_file("STOP single ID $id - descarga incompleta eliminada por señal de detención");
            exit;
        }

        if ($pag < $total_paginas) {
            delay(DELAY_PAGE_MIN, DELAY_PAGE_MAX);
        }
    }

    // ── 5. Convertir todas las imágenes a WebP al 85% ──
    if ($paginas_ok > 0) {
        send_progress([
            'type'    => 'info',
            'message' => "🔄 Convirtiendo imágenes a WebP al 85% de calidad..."
        ]);
        $webp_stats = convertir_comic_a_webp($dir_path, 85);
        if ($webp_stats['converted'] > 0) {
            $ahorro = $webp_stats['bytes_ahorrados'];
            $ahorro_formateado = ($ahorro >= 1073741824) ? number_format($ahorro / 1073741824, 2) . ' GB'
                               : (($ahorro >= 1048576)    ? number_format($ahorro / 1048576, 2) . ' MB'
                               : number_format($ahorro / 1024, 2) . ' KB');
            send_progress([
                'type'    => 'success',
                'message' => "✅ WebP: {$webp_stats['converted']} imágenes convertidas, ahorrado {$ahorro_formateado}"
            ]);
        } elseif ($webp_stats['skipped'] > 0) {
            send_progress([
                'type'    => 'success',
                'message' => "✅ WebP: {$webp_stats['skipped']} imágenes ya estaban en WebP"
            ]);
        }
        if ($webp_stats['failed'] > 0) {
            send_progress([
                'type'    => 'warning',
                'message' => "⚠️ WebP: {$webp_stats['failed']} imágenes fallaron en la conversión"
            ]);
            log_to_db($pdo, $id, 'warning', "WebP: {$webp_stats['failed']} fallos de conversión");
        }
    }

    // ── 6. Actualizar / insertar en BD (después de conversión WebP) ──
    $estado_final = ($paginas_fail === 0) ? 'completo' : (($paginas_ok > 0) ? 'parcial' : 'error');
    registrar_comic($pdo, $id, $titulo, null, $autor, $artista, $tags, $sinopsis,
                    $idioma, $rating, $total_paginas, $paginas_ok, $paginas_fail,
                    $estado_final, $dir_path, $taxonomiasJson);

    log_to_db($pdo, $id, 'success', "Descarga {$estado_final}: {$paginas_ok}/{$total_paginas} páginas");

    send_progress([
        'type'    => 'complete',
        'message' => "🎉 ¡CÓMIC DESCARGADO!  «{$titulo}» (ID {$id}) — {$paginas_ok}/{$total_paginas} páginas"
    ]);

    log_to_file("FIN single ID $id - $titulo - Estado: $estado_final - $paginas_ok/$total_paginas páginas");
    exit;
}


// ════════════════════════════════════════════════════════════════
//  MODO B — UNIVERSO / BATCH (MEJORADO CON PAGINACIÓN)
// ════════════════════════════════════════════════════════════════

if ($action === 'batch') {
    $universo = extraer_universo($url) ?? 'Desconocido';

    send_progress([
        'type'    => 'info',
        'message' => "🌌 Universo: «{$universo}»"
    ]);
    send_progress([
        'type'    => 'info',
        'message' => "📄 Página inicial del listado: {$start_page}  |  Máx. cómics: {$max_comics}"
    ]);

    // ── Verificar historial: si la URL ya fue procesada, reanudar desde la siguiente página ──
    $ultima_pagina_historial = obtener_ultima_pagina_historial($pdo, $url);
    if ($ultima_pagina_historial > 0 && $start_page <= $ultima_pagina_historial) {
        $pagina_resumen = $ultima_pagina_historial;
        // Si el usuario no especificó una página inicial (es el default), auto-ajustamos
        $start_page_original = $start_page;
        $start_page = $ultima_pagina_historial + 1;

        send_progress([
            'type'    => 'info',
            'message' => "📋 Historial encontrado para esta URL — última página procesada: {$pagina_resumen}"
        ]);
        send_progress([
            'type'    => 'info',
            'message' => "🔄 Reanudando automáticamente desde página {$start_page}"
        ]);

        log_to_file("HISTORIAL: URL ya procesada hasta página $pagina_resumen. Reanudando desde página $start_page.");
    }

    // ── Inicializar / actualizar progreso batch en BD ──
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO batch_progreso (universo, url_base, pagina_actual, max_comics, en_progreso, fecha_inicio)
             VALUES (?, ?, ?, ?, TRUE, NOW())
             ON DUPLICATE KEY UPDATE
             url_base = VALUES(url_base),
             max_comics = VALUES(max_comics),
             en_progreso = TRUE,
             fecha_inicio = COALESCE(fecha_inicio, NOW()),
             fecha_fin = NULL'
        );
        $stmt->execute([$universo, $url, $start_page, $max_comics]);
    } catch (Exception $e) {
        log_to_file("Error al inicializar batch_progreso: " . $e->getMessage());
        send_progress([
            'type'    => 'warning',
            'message' => "⚠️ No se pudo registrar el progreso batch en BD: " . $e->getMessage()
        ]);
    }

    $comics_descargados = 0;
    $comics_omitidos    = 0;
    $comics_errores     = 0;
    $stop_detected      = false;
    $current_page       = $start_page;
    $total_enlaces_temp = 0;
    $pagina_inicial_efectiva = $start_page; // Guardamos para el historial

    // ── Recolectar enlaces paginando ──
    $todos_enlaces = [];
    while (count($todos_enlaces) < $max_comics) {
        $html_pagina = obtener_html_paginado($url, $current_page);
        if ($html_pagina === null) {
            send_progress([
                'type'    => 'warning',
                'message' => "No se pudo obtener la página {$current_page} del listado. Deteniendo paginación."
            ]);
            break;
        }

        $enlaces_pagina = extraer_enlaces_universo_dom($html_pagina);

        // Sin enlaces nuevos → fin del listado
        if (empty($enlaces_pagina)) {
            send_progress([
                'type'    => 'info',
                'message' => "📭 No se encontraron más enlaces en página {$current_page}. Fin del listado."
            ]);
            break;
        }

        $nuevos = 0;
        foreach ($enlaces_pagina as $link) {
            if (!in_array($link, $todos_enlaces)) {
                $todos_enlaces[] = $link;
                $nuevos++;
                if (count($todos_enlaces) >= $max_comics) break;
            }
        }

        send_progress([
            'type'    => 'info',
            'message' => "📑 Página {$current_page}: +{$nuevos} enlaces nuevos (total: " . count($todos_enlaces) . ")"
        ]);

        // Actualizar progreso batch
        $stmt = $pdo->prepare(
            'UPDATE batch_progreso SET pagina_actual = ?, comics_obtenidos = ? WHERE universo = ?'
        );
        $stmt->execute([$current_page, count($todos_enlaces), $universo]);

        // Actualizar historial: registrar la última página procesada
        actualizar_ultima_pagina_historial($pdo, $url, $current_page);

        $current_page++;

        // Pausa entre páginas del listado (evitar rate limiting)
        delay(1.0, 2.0);
    }

    $total_comics_encontrados = count($todos_enlaces);

    if ($total_comics_encontrados === 0) {
        send_progress([
            'type'    => 'error',
            'message' => "No se encontraron cómics en {$url}"
        ]);
        $stmt = $pdo->prepare('UPDATE batch_progreso SET en_progreso = FALSE, fecha_fin = NOW() WHERE universo = ?');
        $stmt->execute([$universo]);
        exit;
    }

    send_progress([
        'type'    => 'info',
        'message' => "🔗 Se encontraron {$total_comics_encontrados} cómics en total (páginas {$start_page}-" . ($current_page - 1) . ")"
    ]);
    send_progress([
        'type'    => 'divider',
        'message' => '═══════════════════════════════════════════════'
    ]);

    // ── Procesar cada cómic ──
    foreach ($todos_enlaces as $idx => $comic_url) {
        if ($comics_descargados >= $max_comics) {
            send_progress([
                'type'    => 'info',
                'message' => "⏹️  Se alcanzó el límite de {$max_comics} cómics. Deteniendo."
            ]);
            break;
        }

        $idx_humano = $idx + 1;

        send_progress([
            'type'    => 'divider',
            'message' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                         "  📚 Cómic {$idx_humano} de {$total_comics_encontrados}\n" .
                         "  🔗 {$comic_url}\n" .
                         "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        ]);

        $id = extraer_id($comic_url);
        if ($id === null) {
            send_progress(['type' => 'warning', 'message' => "No se pudo extraer ID, saltando..."]);
            continue;
        }

        // ── Verificar blacklist (mangas eliminados por el usuario) ──
        if (verificar_eliminado($pdo, $id)) {
            send_progress([
                'type'    => 'info',
                'message' => "⛔ Cómic ID {$id} está en la lista de eliminados. Saltando..."
            ]);
            $comics_omitidos++;
            log_to_db($pdo, $id, 'info', "Omitido por blacklist (batch)");
            $stmt = $pdo->prepare(
                'UPDATE batch_progreso SET comics_omitidos = comics_omitidos + 1 WHERE universo = ?'
            );
            $stmt->execute([$universo]);
            continue;
        }

        // ── Verificar duplicado (método mejorado) ──
        // Primero obtenemos el título para la verificación en disco
        $html_comic = obtener_html($comic_url);
        if ($html_comic === null) {
            send_progress(['type' => 'warning', 'message' => "No se pudo obtener HTML del cómic {$id}, saltando..."]);
            $comics_errores++;
            continue;
        }

        $xpath_comic = crear_xpath($html_comic);
        if (!$xpath_comic) {
            send_progress(['type' => 'warning', 'message' => "Error parseando HTML del cómic {$id}, saltando..."]);
            $comics_errores++;
            continue;
        }

        $titulo    = extraer_titulo($xpath_comic, $html_comic);
        $duplicado = verificar_duplicado_completo($pdo, $id, $titulo);

        if ($duplicado) {
            send_progress([
                'type'    => 'info',
                'message' => "⏭️  Cómic «{$titulo}» (ID {$id}) ya descargado completamente. Saltando..."
            ]);
            $comics_omitidos++;

            log_to_db($pdo, $id, 'info', "Omitido por duplicado (batch {$universo})");

            // Actualizar batch progreso
            $stmt = $pdo->prepare(
                'UPDATE batch_progreso SET comics_omitidos = comics_omitidos + 1 WHERE universo = ?'
            );
            $stmt->execute([$universo]);
            continue;
        }

        // ── Extraer metadatos ──
        $total_paginas = extraer_total_paginas($xpath_comic, $html_comic);
        $autor         = extraer_autor($xpath_comic, $html_comic);
        $tags          = extraer_tags($xpath_comic, $html_comic);           // "Etiquetas:" section → tags
        $series        = extraer_series($xpath_comic);                      // "Series:" section → universos
        $personajes    = extraer_personajes($xpath_comic);                  // "Personajes:" section → personajes
        $categorias    = extraer_categorias($xpath_comic);                  // "Categorías:" section → tipos
        $sinopsis      = extraer_sinopsis($xpath_comic, $html_comic);
        $idioma        = extraer_idioma($xpath_comic, $html_comic);
        $rating        = extraer_rating($xpath_comic, $html_comic);

        // ── Procesar taxonomías: combinar universo de batch + series del HTML ──
        $universoCombinado = $universo; // universo base del batch (URL)
        if ($series !== null) {
            $universoCombinado = $series; // "Series:" del HTML tiene prioridad
        }
        $taxData = $taxProcessor->processFromScraper([
            'tags'       => $tags,
            'universo'   => $universoCombinado,
            'idioma'     => $idioma,
            'autor'      => $autor,
            'tipo'       => $categorias,
            'personajes' => $personajes,
        ]);
        $taxonomiasJson = json_encode($taxData, JSON_UNESCAPED_UNICODE);

        send_progress([
            'type'    => 'info',
            'message' => "🏷️  «{$titulo}» — {$total_paginas} páginas" .
                         ($autor ? "  |  ✍️ {$autor}" : "") .
                         "  |  🏷️ " . count($taxData['etiquetas']) . " etiquetas" .
                         "  |  🌐 " . ($taxData['idioma'] ?? '?')
        ]);

        // ── Crear directorio ──
        $dir_path = crear_directorio_comic($id, $titulo);
        if ($dir_path === null) {
            $comics_errores++;
            continue;
        }

        // ── Descargar cada página ──
        $paginas_ok   = 0;
        $paginas_fail = 0;

        for ($pag = 1; $pag <= $total_paginas; $pag++) {
            // Verificar si la imagen ya existe (reanudación)
            $filename = str_pad($pag, 3, '0', STR_PAD_LEFT);
            // Verificar si el archivo ya existe (scandir directo, compatible con UTF-8)
            $existing = [];
            foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                $test_path = $dir_path . '/' . $filename . '.' . $ext;
                if (is_file($test_path)) {
                    $existing[] = $test_path;
                    break;
                }
            }
            if (!empty($existing)) {
                $paginas_ok++;
                continue;
            }

            $img_url  = SITE_VIEW . "/{$id}/{$pag}";
            $filepath = $dir_path . '/' . $filename . '.jpg';

            send_progress([
                'type'    => 'progress',
                'current' => $pag,
                'total'   => $total_paginas,
                'message' => "⬇️  Página {$pag}/{$total_paginas}"
            ]);

            $retries = 0;
            $ok = descargar_imagen($img_url, $filepath, $retries);

            if ($ok) {
                $paginas_ok++;
            } else {
                $paginas_fail++;
                send_progress([
                    'type'    => 'warning',
                    'message' => "⚠️  Página {$pag} omitida"
                ]);
            }

            // ── Check stop signal after each page download ──
            if (check_stop_signal()) {
                send_progress([
                    'type'    => 'warning',
                    'message' => "⏹  Señal de detención. Limpiando descarga incompleta del cómic actual..."
                ]);
                cleanup_incomplete_comic($pdo, $id, $dir_path);
                log_to_file("STOP batch ID $id (comic $idx_humano) - descarga incompleta eliminada");
                $stop_detected = true;
                break;
            }

            if ($pag < $total_paginas) {
                delay(DELAY_PAGE_MIN, DELAY_PAGE_MAX);
            }
        }

        // Si se detectó stop, salir del bucle de cómics
        if ($stop_detected) break;

        // ── 5b. Convertir todas las imágenes a WebP al 85% ──
        if ($paginas_ok > 0) {
            send_progress([
                'type'    => 'info',
                'message' => "🔄 Convirtiendo imágenes a WebP al 85%..."
            ]);
            $webp_stats = convertir_comic_a_webp($dir_path, 85);
            if ($webp_stats['converted'] > 0) {
                $ahorro = $webp_stats['bytes_ahorrados'];
                $ahorro_formateado = ($ahorro >= 1073741824) ? number_format($ahorro / 1073741824, 2) . ' GB'
                                   : (($ahorro >= 1048576)    ? number_format($ahorro / 1048576, 2) . ' MB'
                                   : number_format($ahorro / 1024, 2) . ' KB');
                send_progress([
                    'type'    => 'success',
                    'message' => "✅ WebP: {$webp_stats['converted']} imágenes, ahorrado {$ahorro_formateado}"
                ]);
            } elseif ($webp_stats['skipped'] > 0) {
                send_progress([
                    'type'    => 'success',
                    'message' => "✅ WebP: {$webp_stats['skipped']} imágenes ya en WebP"
                ]);
            }
            if ($webp_stats['failed'] > 0) {
                send_progress([
                    'type'    => 'warning',
                    'message' => "⚠️ WebP: {$webp_stats['failed']} fallos de conversión"
                ]);
            }
        }

        // ── Guardar en BD ──
        $estado_final = ($paginas_fail === 0) ? 'completo' : (($paginas_ok > 0) ? 'parcial' : 'error');
        registrar_comic($pdo, $id, $titulo, $universo, $autor, null, $tags, $sinopsis,
                        $idioma, $rating, $total_paginas, $paginas_ok, $paginas_fail,
                        $estado_final, $dir_path, $taxonomiasJson);

        if ($paginas_ok > 0) {
            $comics_descargados++;
            send_progress([
                'type'    => 'complete',
                'message' => "✅ «{$titulo}» — {$paginas_ok}/{$total_paginas} páginas"
            ]);
        } else {
            $comics_errores++;
            send_progress([
                'type'    => 'error',
                'message' => "❌ «{$titulo}» — Falló la descarga"
            ]);
        }

        log_to_db($pdo, $id, 'success', "Batch {$universo}: {$estado_final} ({$paginas_ok}/{$total_paginas})");

        // ── Actualizar batch progreso ──
        $stmt = $pdo->prepare(
            'UPDATE batch_progreso SET
             comics_descargados = comics_descargados + 1,
             comics_errores = comics_errores + ?
             WHERE universo = ?'
        );
        $stmt->execute([($estado_final === 'error' ? 1 : 0), $universo]);

        // ── Check stop signal before inter-comic delay ──
        if (check_stop_signal()) {
            $stop_detected = true;
            send_progress([
                'type'    => 'warning',
                'message' => "⏹  Señal de detención recibida. Deteniendo proceso batch..."
            ]);
            log_to_file("STOP batch $universo - detenido entre cómics (después de ID $id)");
            break;
        }

        // ── Pausa entre cómics ──
        if ($idx < count($todos_enlaces) - 1 && $comics_descargados < $max_comics) {
            delay(DELAY_COMIC_MIN, DELAY_COMIC_MAX);
        }
    }

    // ── Si se detuvo por señal, limpiar estado batch ──
    if ($stop_detected) {
        $stmt = $pdo->prepare('UPDATE batch_progreso SET en_progreso = FALSE, fecha_fin = NOW() WHERE universo = ?');
        $stmt->execute([$universo]);

        send_progress([
            'type'    => 'done',
            'message' => "⏹  PROCESO DETENIDO POR USUARIO\n" .
                         "  • Universo: «{$universo}»\n" .
                         "  • Cómics descargados: {$comics_descargados}\n" .
                         "  • Omitidos (duplicados): {$comics_omitidos}\n" .
                         "  • Errores: {$comics_errores}"
        ]);
        log_to_file("FIN STOP batch $universo - Detenido por usuario. Descargados: $comics_descargados, Omitidos: $comics_omitidos, Errores: $comics_errores");
        exit;
    }

    // ── Finalizar batch ──
    $stmt = $pdo->prepare(
        'UPDATE batch_progreso SET
         en_progreso = FALSE,
         fecha_fin = NOW()
         WHERE universo = ?'
    );
    $stmt->execute([$universo]);

    // ── Guardar historial de la URL procesada ──
    $ultima_pagina_procesada = $current_page - 1; // current_page se incrementó después de la última
    guardar_historial_batch(
        $pdo, $url, $universo,
        $pagina_inicial_efectiva, $ultima_pagina_procesada,
        $max_comics, $total_comics_encontrados,
        $comics_descargados, $comics_omitidos, $comics_errores,
        true // completado
    );

    send_progress([
        'type'    => 'done',
        'message' => "🎉 ¡PROCESO COMPLETADO!\n" .
                     "  • Universo: «{$universo}»\n" .
                     "  • Cómics encontrados: {$total_comics_encontrados}\n" .
                     "  • Descargados: {$comics_descargados}\n" .
                     "  • Omitidos (duplicados): {$comics_omitidos}\n" .
                     "  • Errores: {$comics_errores}\n" .
                     "  • Páginas del listado: {$pagina_inicial_efectiva} - {$ultima_pagina_procesada}\n" .
                     ($ultima_pagina_historial > 0 ? "  • Reanudado desde historial: sí (previo hasta pág. {$ultima_pagina_historial})\n" : "")
    ]);

    log_to_file("FIN batch $universo - Descargados: $comics_descargados, Omitidos: $comics_omitidos, Errores: $comics_errores");
    exit;
}
