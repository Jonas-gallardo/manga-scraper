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

// ── WebP Conversion Timeout (seconds) ──
// Tiempo máximo por imagen individual. Si cwebp se cuelga con una imagen
// corrupta, se mata el proceso y se salta esa imagen. Evita que el batch
// entero se quede pegado indefinidamente.
define('WEBP_TIMEOUT_PER_IMAGE', 15);

// Tiempo máximo total para convertir un cómic entero (se ignora si es 0).
// Si el cómic tiene 34 imágenes y cada una toma 15s, el timeout total
// sería 34 × 15 = 510s. Con 120s de margen extra para copias de archivos.
// Un valor de 0 deshabilita el timeout total.
define('WEBP_TIMEOUT_TOTAL_COMIC', 0);

// Máximo de fallos consecutivos antes de abortar la conversión del cómic.
// Si fallan 5 imágenes seguidas, asumimos que cwebp no está disponible
// y guardamos las imágenes originales tal cual.
define('WEBP_MAX_CONSECUTIVE_FAILS', 5);

/**
 * Ejecuta un comando con timeout usando proc_open.
 * Más seguro que exec() porque no se cuelga si el proceso hijo se bloquea.
 *
 * @param string $command Comando a ejecutar (ya escapado)
 * @param int $timeout_seconds Tiempo máximo en segundos
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function ejecutar_con_timeout(string $command, int $timeout_seconds = 15): array {
    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['exit_code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    // Cerrar stdin inmediatamente
    fclose($pipes[0]);

    // Establecer pipes como no bloqueantes
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start_time = time();
    $timed_out = false;

    while (true) {
        $status = proc_get_status($process);

        if (!$status['running']) {
            // Proceso terminó — leer lo que quede
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return [
                'exit_code' => $status['exitcode'],
                'stdout'    => $stdout,
                'stderr'    => $stderr,
            ];
        }

        // Timeout check
        if ((time() - $start_time) >= $timeout_seconds) {
            $timed_out = true;
            break;
        }

        // Leer fragmentos disponibles
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        $changed = stream_select($read, $write, $except, 0, 200000); // 200ms poll

        if ($changed > 0) {
            foreach ($read as $pipe) {
                $data = fread($pipe, 8192);
                if ($data === false || $data === '') continue;
                if ($pipe === $pipes[1]) {
                    $stdout .= $data;
                } else {
                    $stderr .= $data;
                }
            }
        }
    }

    // ── Timeout: matar el proceso ──
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (isset($status) && $status['running']) {
        // SIGTERM primero
        proc_terminate($process, 15);
        usleep(500000); // 500ms grace

        $status = proc_get_status($process);
        if ($status['running']) {
            // SIGKILL
            proc_terminate($process, 9);
            usleep(100000);
        }
    }

    proc_close($process);

    return [
        'exit_code' => -2,  // -2 = timeout
        'stdout'    => $stdout,
        'stderr'    => $stderr . "\n[TIMEOUT tras {$timeout_seconds}s]",
    ];
}

/**
 * Convierte TODAS las imágenes de un directorio de cómic a WebP.
 * Usa cwebp CLI con proc_open + timeout para evitar bloqueos.
 * Si cwebp falla consecutivamente N veces, se aborta la conversión del cómic
 * y se conservan las imágenes originales.
 *
 * @param string $dir_path Ruta absoluta del directorio del cómic
 * @param int $quality Calidad WebP (1-100), default 85
 * @return array{converted: int, skipped: int, failed: int, bytes_original: int, bytes_webp: int, bytes_ahorrados: int, aborted: bool}
 */
function convertir_comic_a_webp(string $dir_path, int $quality = 85): array {
    $stats = [
        'converted'       => 0,
        'skipped'         => 0,
        'failed'          => 0,
        'bytes_original'  => 0,
        'bytes_webp'      => 0,
        'bytes_ahorrados' => 0,
        'aborted'         => false,
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

    $consecutive_fails = 0;
    $total_start_time  = time();
    $timeout_per_image = defined('WEBP_TIMEOUT_PER_IMAGE') ? WEBP_TIMEOUT_PER_IMAGE : 15;
    $max_consecutive   = defined('WEBP_MAX_CONSECUTIVE_FAILS') ? WEBP_MAX_CONSECUTIVE_FAILS : 5;
    $total_timeout     = defined('WEBP_TIMEOUT_TOTAL_COMIC') ? WEBP_TIMEOUT_TOTAL_COMIC : 0;

    foreach ($files as $filepath) {
        // ── Guarda contra timeout total del cómic ──
        if ($total_timeout > 0 && (time() - $total_start_time) >= $total_timeout) {
            $stats['aborted'] = true;
            break;
        }

        // ── Guarda contra fallos consecutivos ──
        if ($consecutive_fails >= $max_consecutive) {
            $stats['aborted'] = true;
            break;
        }

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
            @unlink($filepath);
            $consecutive_fails = 0; // reset porque esto no es un fallo
            continue;
        }

        $original_size = @filesize($filepath);
        if ($original_size === false || $original_size === 0) {
            $stats['failed']++;
            $consecutive_fails++;
            continue;
        }

        $exito = false;

        // ── CONVERSIÓN A WebP vía cwebp CLI con timeout ──
        // PROBLEMA: cwebp no puede abrir archivos en rutas con caracteres UTF-8
        //   (ej. 「｜」U+FF5C, acentos, kanji, etc.). La ruta se pasa con
        //   escapeshellarg() pero cwebp internamente no soporta UTF-8 en paths.
        //
        // SOLUCIÓN: Copiar el archivo original a /tmp (ASCII), ejecutar cwebp
        //   sobre el temp, y luego copiar el WebP resultante al destino UTF-8
        //   usando PHP. Esto evita que cwebp tenga que tocar paths no-ASCII.

        $temp_input = sys_get_temp_dir() . '/' . uniqid('cwebp_in_', true);
        $temp_webp  = sys_get_temp_dir() . '/' . uniqid('cwebp_out_', true) . '.webp';

        // Copiar archivo original a temp ASCII (PHP maneja UTF-8 nativamente)
        if (!@copy($filepath, $temp_input)) {
            $stats['failed']++;
            $consecutive_fails++;
            @unlink($temp_input);
            @unlink($temp_webp);
            continue;
        }

        // ── Intento 1: cwebp con LD_LIBRARY_PATH ──
        $cmd1 = sprintf(
            'LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu cwebp -q %d %s -o %s 2>/dev/null',
            $quality,
            escapeshellarg($temp_input),
            escapeshellarg($temp_webp)
        );
        $result1 = ejecutar_con_timeout($cmd1, $timeout_per_image);

        $intento1_ok = ($result1['exit_code'] === 0 && file_exists($temp_webp) && @filesize($temp_webp) > 0);

        // ── Intento 2: cwebp sin LD_LIBRARY_PATH (fallback) ──
        if (!$intento1_ok) {
            if (file_exists($temp_webp)) @unlink($temp_webp);

            $cmd2 = sprintf(
                'cwebp -q %d %s -o %s 2>/dev/null',
                $quality,
                escapeshellarg($temp_input),
                escapeshellarg($temp_webp)
            );
            $result2 = ejecutar_con_timeout($cmd2, $timeout_per_image);

            $intento2_ok = ($result2['exit_code'] === 0 && file_exists($temp_webp) && @filesize($temp_webp) > 0);

            if (!$intento2_ok) {
                if (file_exists($temp_webp)) @unlink($temp_webp);
            }
        }

        // ── Copiar desde temp al destino final (PHP maneja UTF-8 nativamente) ──
        if (file_exists($temp_webp) && @filesize($temp_webp) > 0) {
            $webp_data = @file_get_contents($temp_webp);
            if ($webp_data !== false && strlen($webp_data) > 0) {
                $escrito = @file_put_contents($webp_path, $webp_data);
                if ($escrito !== false && file_exists($webp_path) && @filesize($webp_path) > 0) {
                    $exito = true;
                }
            }
        }

        // Limpiar temporales siempre
        @unlink($temp_input);
        @unlink($temp_webp);

        if ($exito) {
            $webp_size = @filesize($webp_path) ?: 0;
            $stats['converted']++;
            $stats['bytes_original'] += $original_size;
            $stats['bytes_webp'] += $webp_size;
            $consecutive_fails = 0; // reset contador

            // Eliminar el archivo original pesado
            @unlink($filepath);
        } else {
            $stats['failed']++;
            $consecutive_fails++;

            // Limpiar archivo basura si se creó
            if (file_exists($webp_path)) {
                @unlink($webp_path);
            }
        }
    }

    $stats['bytes_ahorrados'] = $stats['bytes_original'] - $stats['bytes_webp'];

    return $stats;
}
