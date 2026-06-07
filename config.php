<?php
/**
 * config.php
 *
 * Configuración centralizada de la aplicación Comic Scraper Pro.
 * Lee de config.json si existe (configurable desde la UI), 
 * o usa valores por defecto.
 *
 * Las credenciales de BD y la URL del sitio se configuran
 * desde setup.php o desde la sección "Configuración" en la UI.
 */

// ── Cargar configuración desde config.json (si existe) ──
$config_file = __DIR__ . '/config.json';
$json_config = [];

if (file_exists($config_file)) {
    $json_config = json_decode(file_get_contents($config_file), true) ?: [];
}

// ── Base de Datos ──
define('DB_HOST',    $json_config['db_host'] ?? 'localhost');
define('DB_NAME',    $json_config['db_name'] ?? 'comics_db');
define('DB_USER',    $json_config['db_user'] ?? 'root');
define('DB_PASS',    $json_config['db_pass'] ?? '');
define('DB_CHARSET', 'utf8mb4');

// ── Sitio destino (configurable desde UI) ──
define('SITE_BASE',       $json_config['site_base_url'] ?? 'https://sitio.com');
define('SITE_VIEW_PATH',  $json_config['site_view_path'] ?? '/view');
define('SITE_BATCH_PATH', $json_config['site_batch_path'] ?? '/parody');
define('SITE_VIEW',       (!empty($json_config['site_view']) ? $json_config['site_view'] : SITE_BASE . SITE_VIEW_PATH));
define('SITE_PARODY',     (!empty($json_config['site_parody']) ? $json_config['site_parody'] : SITE_BASE . SITE_BATCH_PATH));
define('SITE_DOMAIN',     (!empty($json_config['site_domain']) ? $json_config['site_domain'] : parse_url(SITE_BASE, PHP_URL_HOST)));

// ── Directorio de descargas (configurable desde UI) ──
define('DOWNLOADS_DIR', (!empty($json_config['download_path']) ? $json_config['download_path'] : __DIR__ . '/descargas'));

// ── Anti-Ban / Retry ──
define('MAX_RETRIES',        $json_config['max_retries'] ?? 2);
define('RETRY_WAIT_SECONDS', 10);
define('CURL_TIMEOUT',       30);
define('CURL_CONNECT_TIMEOUT', 10);
define('CURL_MAXREDIRS',     5);
define('CURL_SSL_VERIFY',    $json_config['curl_ssl_verify'] ?? false);

// ── Delays (segundos) ──
define('DELAY_PAGE_MIN',  $json_config['delay_page_min'] ?? 1.5);
define('DELAY_PAGE_MAX',  $json_config['delay_page_max'] ?? 3.5);
define('DELAY_COMIC_MIN', $json_config['delay_comic_min'] ?? 5);
define('DELAY_COMIC_MAX', $json_config['delay_comic_max'] ?? 10);

// ── User-Agent ──
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// ── HTTP Headers ──
define('HTTP_HEADERS', serialize([
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
    'Accept-Language: es-ES,es;q=0.9,en;q=0.8',
    'Referer: ' . SITE_BASE . '/',
    'DNT: 1',
    'Connection: keep-alive',
    'Upgrade-Insecure-Requests: 1',
]));

// ── Paginación Batch ──
define('BATCH_DEFAULT_PAGE',      1);
define('BATCH_DEFAULT_PER_PAGE',  50);
define('BATCH_DEFAULT_MAX',       50);
define('BATCH_PAGE_PARAM',        'page');

// ── Logging ──
define('LOG_DIR', __DIR__ . '/logs');
define('LOG_FILE', LOG_DIR . '/scraper.log');
define('LOG_MAX_SIZE', 5 * 1024 * 1024);

// ── Stop Signal (usado por el botón Detener) ──
define('SCRAPER_STOP_FILE', sys_get_temp_dir() . '/scraper_stop.flag');

/**
 * Escanea un directorio y devuelve imágenes ordenadas.
 * Delega a ImageService.
 *
 * @param string $dir Ruta absoluta del directorio
 * @return array<string> Array de rutas completas de imágenes
 */
function escanear_imagenes(string $dir): array {
    static $service = null;
    if ($service === null) {
        $service = new \ScrapApp\Services\ImageService();
    }
    return $service->scanImages($dir);
}

/**
 * Calcula el tamaño total en bytes de un directorio (incluye subdirectorios).
 * Delega a FileService.
 */
function calcular_tamano_dir(string $dir): int {
    static $service = null;
    if ($service === null) {
        $service = new \ScrapApp\Services\FileService();
    }
    return $service->calculateDirectorySize($dir);
}

/**
 * Convierte TODAS las imágenes de un directorio de cómic a WebP.
 * Delega a ImageService.
 *
 * @param string $dir_path Ruta absoluta del directorio del cómic
 * @param int $quality Calidad WebP (1-100), default 85
 * @return array{converted: int, skipped: int, failed: int, bytes_original: int, bytes_webp: int, bytes_ahorrados: int}
 */
function convertir_comic_a_webp(string $dir_path, int $quality = 85): array {
    static $service = null;
    if ($service === null) {
        $service = new \ScrapApp\Services\ImageService();
    }
    return $service->convertToWebp($dir_path, $quality);
}
