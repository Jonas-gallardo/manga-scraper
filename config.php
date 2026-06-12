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

// ── Stop Signal (usado por el botón Detener del scraper) ──
define('SCRAPER_STOP_FILE', sys_get_temp_dir() . '/scraper_stop.flag');

// ── Stop Signal para WP Publisher ──
define('PUBLISH_STOP_FILE', sys_get_temp_dir() . '/publish_stop.flag');

// ── Soft Stop Signal para WP Publisher (termina comic actual y luego para) ──
define('PUBLISH_SOFT_STOP_FILE', sys_get_temp_dir() . '/publish_soft_stop.flag');

// ── Progress State para WP Publisher (JSON file for real-time UI polling) ──
define('PUBLISH_PROGRESS_FILE', sys_get_temp_dir() . '/publish_progress.json');

// ── Lock file for background publishing process ──
define('PUBLISH_LOCK_FILE', sys_get_temp_dir() . '/publish_lock.json');

// ── Path to background CLI script ──
define('PUBLISH_BG_SCRIPT', __DIR__ . '/publish_background.php');

// ── GLOBAL Rate Limiter: minimum time between ANY API call to WordPress ──
// Banahosting rate-limits SSL connections after ~50 rapid requests.
// 1,500,000 us = 1.5 seconds between ALL calls (image uploads, taxonomy lookups, posts, meta...)
// This single constant prevents the site from being overwhelmed.
define('PUBLISH_RATE_LIMIT_SECONDS', 1.5);

// ── Delay between individual image uploads (DENTRO de uploadComicImages) ──
// MIN and MAX microseconds. A random value within this range is chosen per image.
// Con PUBLISH_RATE_LIMIT_SECONDS=1.5s, el total efectivo es 2.0-3.5s entre imágenes,
// lo cual evade el rate-limiting SSL de Banahosting al no ser un patrón fijo.
define('PUBLISH_DELAY_IMAGES_MIN', 500000);   // 0.5 seconds
define('PUBLISH_DELAY_IMAGES_MAX', 2000000);  // 2.0 seconds

// ── Delay between publishing each comic in a batch (microseconds) ──
define('PUBLISH_DELAY_BETWEEN_COMICS', 10000000);  // 10 seconds

// ── Consecutive SSL failure threshold: if N images in a row fail with SSL errors,
//     pause for an extended backoff (2^failures * 10 seconds, capped at 120s).
define('PUBLISH_MAX_CONSECUTIVE_SSL_FAILURES', 3);

// ── cURL Keep-Alive (handle persistente para upload de imágenes) ──
// WARNING: Habilitar esto puede causar ERR_CONNECTION_RESET en el servidor
// durante lotes grandes. El handle persistente reutiliza sesiones SSL,
// y cuando el servidor las purga (>5s idle), corrompe su stack SSL completo
// impidiendo que NINGUNA conexión (ni siquiera fresh) funcione.
// Solo habilitar si el hosting soporta keep-alive de muy larga duración.
define('PERSISTENT_CURL_ENABLED', false);

// ── Base64 Bridge para evadir WAF @validateByteRange 1-255 ──
// Cuando está habilitado, las imágenes se codifican en base64 y se envían
// como JSON al bridge wp-media-bridge.php en lugar de como binario directo
// al REST API. Esto evade la regla del WAF Imunify360/ModSecurity que
// bloquea bytes NULL (comunes en imágenes .webp) en peticiones POST.
// El bridge decodifica el base64 y crea el attachment en WordPress.
define('UPLOAD_USE_BRIDGE', true);
define('UPLOAD_BRIDGE_ENDPOINT', '/wp-media-bridge.php');

// ── SSL Meltdown Cooldown ──
// Si una conexión FRESCA falla con errno 35 (Unknown SSL protocol error),
// significa que el stack SSL del servidor colapsó. Se activa una pausa de
// esta duración (segundos) para que el servidor se recupere.
// 180 segundos = 3 minutos. Ajustar según el hosting.
define('SSL_MELTDOWN_COOLDOWN_SECONDS', 180);

// ── Batch audit logs: cada lote de publicación guarda un JSON completo con
//     estadísticas, resultados por cómic, imágenes fallidas y timestamps,
//     para auditoría post-mortem y diagnóstico de fallos recurrentes.
define('PUBLISH_BATCH_LOG_DIR', __DIR__ . '/logs/batches');

/**
 * Escanea un directorio y devuelve imágenes ordenadas.
 * Reemplaza a glob() que falla con caracteres no-ASCII en las rutas.
 *
 * @param string $dir Ruta absoluta del directorio
 * @return array<string> Array de rutas completas de imágenes
 */
function escanear_imagenes(string $dir): array {
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
        if ($entry === '.' || $entry === '..') continue;
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
 * Calcula el tamaño total en bytes de un directorio (incluye subdirectorios).
 */
function calcular_tamano_dir(string $dir): int {
    $size = 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        $size += $file->getSize();
    }
    return $size;
}

/**
 * Convierte TODAS las imágenes de un directorio de cómic a WebP.
 * Usa PHP GD (imagewebp) como método principal — más fiable con rutas UTF-8,
 * caracteres especiales y permisos. Fallback a cwebp CLI si GD no está disponible.
 *
 * @param string $dir_path Ruta absoluta del directorio del cómic
 * @param int $quality Calidad WebP (1-100), default 85
 * @return array{converted: int, skipped: int, failed: int, bytes_original: int, bytes_webp: int, bytes_ahorrados: int}
 */
function convertir_comic_a_webp(string $dir_path, int $quality = 85): array {
    $stats = [
        'converted'      => 0,
        'skipped'        => 0,
        'failed'         => 0,
        'bytes_original' => 0,
        'bytes_webp'     => 0,
        'bytes_ahorrados' => 0,
    ];

    if (!is_dir($dir_path)) {
        return $stats;
    }

    // Extensiones a convertir (excluimos webp y avif)
    $extensions = ['jpg', 'jpeg', 'png', 'gif'];

    $handle = opendir($dir_path);
    if ($handle === false) {
        return $stats;
    }

    $files = [];
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir_path . '/' . $entry;
        if (is_file($path)) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, $extensions)) {
                $files[] = $path;
            }
        }
    }
    closedir($handle);

    natsort($files);

    foreach ($files as $filepath) {
        $info = pathinfo($filepath);
        $filename_no_ext = $info['filename'];
        $webp_path = $info['dirname'] . '/' . $filename_no_ext . '.webp';

        // Si el webp destino YA existe, considerar como convertido y borrar original
        if (file_exists($webp_path)) {
            $stats['skipped']++;
            $orig_size = @filesize($filepath);
            $webp_size = @filesize($webp_path);
            $stats['bytes_original'] += $orig_size ?: 0;
            $stats['bytes_webp'] += $webp_size ?: 0;
            @unlink($filepath); // Borrar original duplicado
            continue;
        }

        $original_size = @filesize($filepath);
        if ($original_size === false || $original_size === 0) {
            $stats['failed']++;
            continue;
        }

        $exito = false;

        // ── CONVERSIÓN A WebP ──
        // Usamos cwebp CLI vía temp para evitar dos problemas:
        //   1. XAMPP (Apache) tiene libstdc++ antigua que rompe cwebp →
        //      se soluciona con LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu
        //   2. cwebp no puede escribir en rutas con caracteres UTF-8 →
        //      se escribe a /tmp y luego se copia con PHP
        //   3. PHP GD no tiene imagewebp() disponible en este servidor
        //
        // Estrategia: escribir a temp (ASCII), luego file_get_contents + file_put_contents

        $temp_webp = sys_get_temp_dir() . '/' . uniqid('cwebp_', true) . '.webp';

        // ── Intento 1: cwebp con LD_LIBRARY_PATH (para XAMPP/Apache) ──
        $cmd1 = sprintf(
            'LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu cwebp -q %d %s -o %s 2>/dev/null',
            $quality,
            escapeshellarg($filepath),
            escapeshellarg($temp_webp)
        );
        $ret1 = -1;
        exec($cmd1, $out1, $ret1);

        // ── Intento 2: cwebp sin LD_LIBRARY_PATH (para CLI, si el primero falló) ──
        if ($ret1 !== 0 || !file_exists($temp_webp) || filesize($temp_webp) === 0) {
            $cmd2 = sprintf(
                'cwebp -q %d %s -o %s 2>/dev/null',
                $quality,
                escapeshellarg($filepath),
                escapeshellarg($temp_webp)
            );
            $ret2 = -1;
            exec($cmd2, $out2, $ret2);

            // Si ambos fallaron, limpiar temp
            if (($ret2 !== 0 || !file_exists($temp_webp) || filesize($temp_webp) === 0)) {
                if (file_exists($temp_webp)) @unlink($temp_webp);
            }
        }

        // ── Copiar desde temp al destino final ──
        if (file_exists($temp_webp) && filesize($temp_webp) > 0) {
            $webp_data = @file_get_contents($temp_webp);
            if ($webp_data !== false) {
                $escrito = @file_put_contents($webp_path, $webp_data);
                if ($escrito !== false && file_exists($webp_path) && filesize($webp_path) > 0) {
                    $exito = true;
                }
            }
            @unlink($temp_webp);
        }

        if ($exito) {
            $webp_size = @filesize($webp_path) ?: 0;
            $stats['converted']++;
            $stats['bytes_original'] += $original_size;
            $stats['bytes_webp'] += $webp_size;

            // Eliminar el archivo original pesado
            @unlink($filepath);
        } else {
            $stats['failed']++;
            // Si falló pero dejó un archivo basura, limpiarlo
            if (file_exists($webp_path)) {
                @unlink($webp_path);
            }
        }
    }

    $stats['bytes_ahorrados'] = $stats['bytes_original'] - $stats['bytes_webp'];

    return $stats;
}
