<?php
/**
 * content_backfill.php
 *
 * FASE CONTENIDO — Compensación (backfill) del cuerpo de los cómics.
 *
 * Consulta los posts publicados DIRECTAMENTE desde el sitio WordPress
 * (gluglux.com) vía el puente wp-media-bridge.php (acción list_posts),
 * construye un post_content semántico (anti thin-content) y lo escribe
 * vía la acción update_post_content.
 *
 * El cuerpo se arma SIN gastar llamadas de IA, reutilizando datos que ya
 * existen en el sitio:
 *   - post_excerpt (síntesis técnica generada por DeepSeek en la Fase 1)
 *   - taxonomías (universo, personaje, autor, tipo, idioma, etiqueta)
 *   - número de páginas (campo ACF image_comic, devuelto como page_count)
 *
 * USO:
 *   php content_backfill.php                    → lote inicial (offset 0, 50 posts)
 *   php content_backfill.php 0 50               → offset y cantidad
 *   php content_backfill.php 0 50 --dry-run     → sin escribir, solo generar y mostrar
 *   php content_backfill.php 0 50 --only-empty  → solo posts con post_content vacío
 *   php content_backfill.php 0 50 --force       → sobrescribe contenidos existentes
 *   php content_backfill.php --all              → itera TODO el catálogo en lotes de 100
 *   php content_backfill.php --all --only-empty
 *
 * @package ComicScraperPro
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(0);
ignore_user_abort(true);

$isCli = (PHP_SAPI === 'cli');

// ── Leer credenciales de WordPress desde config.json ──
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    respond('error', 'No se encontró config.json', null, $isCli);
}

$cfg = json_decode(file_get_contents($configFile), true) ?: [];
$baseUrl  = rtrim($cfg['wp_base_url'] ?? '', '/');
$username = $cfg['wp_username'] ?? '';
$password = $cfg['wp_app_password'] ?? '';

if (empty($baseUrl) || empty($username) || empty($password)) {
    respond('error', 'Faltan credenciales de WordPress en config.json', null, $isCli);
}

// ── Parámetros ──
if ($isCli) {
    $all       = in_array('--all', $argv, true);
    $dryRun    = in_array('--dry-run', $argv, true);
    $onlyEmpty = in_array('--only-empty', $argv, true);
    $force     = in_array('--force', $argv, true);

    $positional = [];
    $args = array_slice($argv, 1);
    foreach ($args as $arg) {
        if (preg_match('/^\d+$/', $arg)) {
            $positional[] = (int) $arg;
        }
    }

    $offset = $positional[0] ?? 0;
    $limit  = isset($positional[1]) ? max(1, min(100, $positional[1])) : ($all ? 100 : 50);
} else {
    $all       = !empty($_GET['all']);
    $dryRun    = !empty($_GET['dry_run']);
    $onlyEmpty = !empty($_GET['only_empty']);
    $force     = !empty($_GET['force']);
    $offset    = max(0, (int) ($_GET['offset'] ?? 0));
    $limit     = max(1, min(100, (int) ($_GET['limit'] ?? ($all ? 100 : 50))));
}

$authHeader = 'Authorization: Basic ' . base64_encode("{$username}:{$password}");
$bridgeUrl  = $baseUrl . '/wp-media-bridge.php';
$postType   = 'post';

/**
 * Realiza una petición al puente wp-media-bridge.php.
 */
function bridgeRequest(string $url, string $authHeader, ?array $payload = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            $authHeader,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
        CURLOPT_FORBID_REUSE   => true,
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ];

    if ($payload !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'http' => $http,
        'body' => json_decode($raw, true),
        'raw'  => $raw === false ? ('cURL error: ' . $err) : $raw,
    ];
}

/**
 * Imprime una línea de log (CLI o HTML).
 */
function logLine(string $message, bool $isCli): void
{
    if ($isCli) {
        echo $message . PHP_EOL;
    } else {
        echo htmlspecialchars($message) . "<br>" . PHP_EOL;
    }
    @ob_flush();
    @flush();
}

/**
 * Responde en JSON (HTTP) o termina en CLI.
 */
function respond(string $status, string $message, ?array $extra, bool $isCli): void
{
    if ($isCli) {
        echo "[" . strtoupper($status) . "] " . $message . PHP_EOL;
        exit($status === 'error' ? 1 : 0);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $status === 'ok', 'message' => $message], $extra ?? []), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Escapa un string para insertarlo como texto dentro de HTML.
 * Equivalente standalone de esc_html() de WordPress (no disponible aquí).
 */
function contentBackfillEscHtml(string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Construye el post_content semántico a partir de los datos ya existentes.
 * Replica la estructura generada por WPPublisher::buildPostContent().
 *
 * @param string $title  Título del cómic (ya limpio)
 * @param string $excerpt Síntesis técnica (post_excerpt)
 * @param array  $taxonomies Taxonomías con nombres (claves: universo, personaje, autor, tipo, idioma, post_tag)
 * @param int    $pageCount Número de páginas (0 si se desconoce)
 * @return string HTML para post_content
 */
function contentBackfillBuildContent(string $title, string $excerpt, array $taxonomies, int $pageCount): string
{
    $html = '';

    // ── Sinopsis ──
    if (trim($excerpt) !== '') {
        $html .= '<h2>Sinopsis</h2>' . "\n";
        $html .= '<p>' . contentBackfillEscHtml(trim($excerpt)) . '</p>' . "\n";
    }

    // ── Ficha técnica ──
    $ficha = [];
    $map = [
        'universo'  => 'Universo',
        'personaje' => 'Personajes',
        'autor'     => 'Autor',
        'tipo'      => 'Tipo',
        'idioma'    => 'Idioma',
        'post_tag'  => 'Etiquetas',
    ];

    foreach ($map as $key => $label) {
        $values = array_filter(array_map('trim', (array) ($taxonomies[$key] ?? [])));
        if (!empty($values)) {
            $ficha[$label] = implode(', ', $values);
        }
    }

    if (!empty($ficha)) {
        $html .= '<h2>Ficha técnica</h2>' . "\n<ul>\n";
        foreach ($ficha as $clave => $valor) {
            $html .= '<li><strong>' . contentBackfillEscHtml($clave) . ':</strong> ' . contentBackfillEscHtml($valor) . "</li>\n";
        }
        $html .= "</ul>\n";
    }

    // ── Recuento de páginas ──
    if ($pageCount > 0) {
        $html .= '<p>' . contentBackfillEscHtml(sprintf('Este cómic contiene %d páginas en alta resolución.', $pageCount)) . "</p>\n";
    }

    // ── Galería (placeholder semántico) ──
    if ($pageCount > 0) {
        $html .= '<h2>Galería</h2>' . "\n";
        $html .= '<p>' . contentBackfillEscHtml(sprintf('Explora la galería completa de «%s» a continuación.', $title)) . "</p>\n";
    }

    return trim($html);
}

/**
 * ¿El contenido actual es vacío o irrelevante (thin content)?
 */
function contentBackfillIsEmpty(string $content): bool
{
    $text = trim(strip_tags($content));
    // Vacío o casi vacío (menos de 40 caracteres visibles)
    return mb_strlen($text) < 40;
}

// ── Estado global acumulado (persiste entre lotes en modo --all) ──
$stats = [
    'processed' => 0, // posts con contenido generado
    'written'   => 0,
    'skipped'   => 0,
    'failed'    => 0,
    'batches'   => 0,
    'results'   => [],
];

$currentOffset = $offset;
$batchLimit    = $all ? min(100, max(1, $limit)) : $limit;

if ($all) {
    logLine("Modo --all: iterando TODO el catálogo en lotes de {$batchLimit} a partir del offset {$currentOffset}.", $isCli);
}

do {
    // ── 1. Obtener posts desde el sitio ──
    logLine(PHP_EOL . "── Lote (offset={$currentOffset}, limit={$batchLimit}) ──", $isCli);

    $listResp = bridgeRequest(
        $bridgeUrl . '?action=list_posts',
        $authHeader,
        ['post_type' => $postType, 'per_page' => $batchLimit, 'offset' => $currentOffset]
    );

    if ($listResp['http'] < 200 || $listResp['http'] >= 300 || empty($listResp['body']['success'])) {
        respond('error', 'Error consultando posts: HTTP ' . $listResp['http'] . ' — ' . substr($listResp['raw'], 0, 500), null, $isCli);
    }

    $posts = $listResp['body']['posts'] ?? [];
    logLine("Se obtuvieron " . count($posts) . " posts.", $isCli);

    if (empty($posts)) {
        if (!$all) {
            respond('ok', 'No hay posts para procesar en este rango.', ['processed' => 0], $isCli);
        }
        logLine("Sin más posts. Iteración finalizada.", $isCli);
        break;
    }

    // ── 2. Procesar cada post ──
    foreach ($posts as $idx => $post) {
        $postId     = (int) ($post['id'] ?? 0);
        $title      = (string) ($post['title'] ?? '');
        $excerpt    = (string) ($post['excerpt'] ?? '');
        $content    = (string) ($post['content'] ?? '');
        $pageCount  = (int) ($post['page_count'] ?? 0);
        $tax        = $post['taxonomies'] ?? [];

        if ($postId <= 0) {
            $stats['failed']++;
            logLine("  ✗ Post sin ID válido (índice {$idx})", $isCli);
            continue;
        }

        // Filtrar según flags
        if (!$force && $onlyEmpty && !contentBackfillIsEmpty($content)) {
            $stats['skipped']++;
            logLine("  ⏭ Post ID {$postId}: ya tiene contenido, omitido", $isCli);
            continue;
        }
        if (!$force && !$onlyEmpty && !contentBackfillIsEmpty($content)) {
            $stats['skipped']++;
            logLine("  ⏭ Post ID {$postId}: ya tiene contenido, omitido", $isCli);
            continue;
        }

        $cleanTitle = trim((string) preg_replace('/\[.*?\]|\(.*?\)/', '', $title));
        $newContent = contentBackfillBuildContent($cleanTitle, $excerpt, $tax, $pageCount);

        // Si no hay nada que escribir (sin excerpt, sin taxonomías, sin páginas), omitir
        if ($newContent === '') {
            $stats['skipped']++;
            logLine("  ⏭ Post ID {$postId} «{$cleanTitle}»: sin datos suficientes para construir contenido, omitido", $isCli);
            continue;
        }

        logLine("  ▶ Post ID {$postId} «{$cleanTitle}» — contenido generado (" . mb_strlen(strip_tags($newContent)) . " chars visibles)", $isCli);

        if ($dryRun) {
            logLine("      [dry-run] " . mb_substr(strip_tags($newContent), 0, 100) . '...', $isCli);
            $stats['results'][] = ['post_id' => $postId, 'status' => 'dry_run', 'content' => $newContent];
            $stats['processed']++;
            usleep(100000);
            continue;
        }

        // ── 3. Escribir contenido en el sitio ──
        $updResp = bridgeRequest(
            $bridgeUrl . '?action=update_post_content',
            $authHeader,
            ['post_id' => $postId, 'content' => $newContent]
        );

        if ($updResp['http'] >= 200 && $updResp['http'] < 300 && !empty($updResp['body']['success'])) {
            $stats['written']++;
            logLine("    ✓ Escrito en post_content (ID {$postId})", $isCli);
            $stats['results'][] = ['post_id' => $postId, 'status' => 'written'];
        } else {
            $stats['failed']++;
            logLine("    ✗ Error escribiendo contenido: HTTP {$updResp['http']} — " . substr($updResp['raw'], 0, 300), $isCli);
            $stats['results'][] = ['post_id' => $postId, 'status' => 'error', 'detail' => substr($updResp['raw'], 0, 300)];
        }

        $stats['processed']++;
        usleep(150000); // 0.15 s entre posts (evita saturar el bridge)
    }

    $stats['batches']++;
    $currentOffset += count($posts);

    if ($all) {
        logLine("Progreso acumulado — procesados: {$stats['processed']} | escritos: {$stats['written']} | omitidos: {$stats['skipped']} | fallidos: {$stats['failed']}", $isCli);
    }

    if ($all && count($posts) < $batchLimit) {
        logLine("Último lote incompleto: iteración finalizada.", $isCli);
        break;
    }
} while ($all);

// ── Resumen ──
$summary = "Procesados: {$stats['processed']} | " .
           ($dryRun ? "(dry-run) " : "Escritos: {$stats['written']} | ") .
           "Omitidos: {$stats['skipped']} | Fallidos: {$stats['failed']}";

logLine(PHP_EOL . str_repeat('─', 60), $isCli);
logLine("RESUMEN: " . $summary, $isCli);

respond('ok', $summary, [
    'processed' => $stats['processed'],
    'written'   => $stats['written'],
    'skipped'   => $stats['skipped'],
    'failed'    => $stats['failed'],
    'batches'   => $stats['batches'],
    'results'   => $stats['results'],
], $isCli);
