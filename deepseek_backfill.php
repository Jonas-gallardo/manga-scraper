<?php
/**
 * deepseek_backfill.php
 *
 * FASE 1 — Compensación (backfill).
 *
 * Consulta los posts publicados DIRECTAMENTE desde el sitio WordPress
 * (gluglux.com) vía el puente wp-media-bridge.php, genera la síntesis
 * técnica con DeepSeek y escribe el resultado en post_excerpt.
 *
 * Consulta desde el sitio (no la BD local) porque ~300 posts fueron
 * cargados manualmente y solo el sitio conoce sus IDs reales.
 *
 * USO:
 *   php deepseek_backfill.php                     → lote inicial (offset 0, 20 posts)
 *   php deepseek_backfill.php 0 20                → offset y cantidad
 *   php deepseek_backfill.php 0 20 --dry-run      → sin escribir, solo generar y mostrar
 *   php deepseek_backfill.php 0 20 --only-empty   → solo posts con excerpt vacío
 *   php deepseek_backfill.php 0 20 --force        → sobrescribe excerpt existentes (re-corregir)
 *
 * También accesible por HTTP:
 *   deepseek_backfill.php?offset=0&limit=20&dry_run=1&force=1
 *
 * @package ComicScraperPro
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/src/AI/DeepSeekClient.php';

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
    $offset   = isset($argv[1]) ? max(0, (int) $argv[1]) : 0;
    $limit    = isset($argv[2]) ? max(1, min(100, (int) $argv[2])) : 20;
    $dryRun   = in_array('--dry-run', $argv, true);
    $onlyEmpty = in_array('--only-empty', $argv, true);
    $force    = in_array('--force', $argv, true);
} else {
    $offset   = max(0, (int) ($_GET['offset'] ?? 0));
    $limit    = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $dryRun   = !empty($_GET['dry_run']);
    $onlyEmpty = !empty($_GET['only_empty']);
    $force    = !empty($_GET['force']);
}

$authHeader = 'Authorization: Basic ' . base64_encode("{$username}:{$password}");
$bridgeUrl  = $baseUrl . '/wp-media-bridge.php';
$postType   = 'post';

/**
 * Realiza una petición al puente wp-media-bridge.php.
 *
 * @param string $url URL completa (con ?action=...)
 * @param string $authHeader Header Basic Auth
 * @param array|null $payload JSON a enviar (POST) o null para GET
 * @return array{http:int, body:array|null, raw:string}
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

// ── 1. Obtener posts desde el sitio ──
logLine("Consultando posts desde {$baseUrl} (post_type={$postType}, offset={$offset}, limit={$limit})...", $isCli);

$listResp = bridgeRequest(
    $bridgeUrl . '?action=list_posts',
    $authHeader,
    ['post_type' => $postType, 'per_page' => $limit, 'offset' => $offset]
);

if ($listResp['http'] < 200 || $listResp['http'] >= 300 || empty($listResp['body']['success'])) {
    respond('error', 'Error consultando posts: HTTP ' . $listResp['http'] . ' — ' . substr($listResp['raw'], 0, 500), null, $isCli);
}

$posts = $listResp['body']['posts'] ?? [];
logLine("Se obtuvieron " . count($posts) . " posts.", $isCli);

if (empty($posts)) {
    respond('ok', 'No hay posts para procesar en este rango.', ['processed' => 0], $isCli);
}

// ── 2. Procesar cada post ──
$deepseek = new DeepSeekClient();
$processed = 0;
$generated = 0;
$written   = 0;
$skipped   = 0;
$failed    = 0;
$results   = [];

foreach ($posts as $idx => $post) {
    $postId = (int) ($post['id'] ?? 0);
    $title  = (string) ($post['title'] ?? '');
    $tax    = $post['taxonomies'] ?? [];

    if ($postId <= 0) {
        $failed++;
        logLine("  ✗ Post sin ID válido (índice {$idx})", $isCli);
        continue;
    }

    // Filtrar solo posts con excerpt vacío si se solicitó (y no se fuerza sobrescritura)
    if (!$force && $onlyEmpty && !empty(trim((string) ($post['excerpt'] ?? '')))) {
        $skipped++;
        logLine("  ⏭ Post ID {$postId}: ya tiene excerpt, omitido", $isCli);
        continue;
    }

    // Extraer campos de taxonomías (nombres) para el prompt
    $universo   = implode(', ', $tax['universo'] ?? []);
    $personajes = implode(', ', $tax['personaje'] ?? []);
    $tipo       = implode(', ', $tax['tipo'] ?? []);
    $etiquetas  = implode(', ', $tax['post_tag'] ?? []);

    $cleanTitle = DeepSeekClient::cleanTitle($title);
    logLine("  ▶ Post ID {$postId}: «{$cleanTitle}» — generando...", $isCli);

    $excerpt = $deepseek->generateExcerpt($title, $universo, $personajes, $tipo, $etiquetas);

    if ($excerpt === null) {
        $failed++;
        logLine("    ✗ Error generando excerpt: " . $deepseek->getLastError(), $isCli);
        $results[] = ['post_id' => $postId, 'status' => 'error', 'detail' => $deepseek->getLastError()];
        usleep(500000); // pausa entre posts incluso en error
        continue;
    }

    $generated++;
    logLine("    ✓ Generado: " . mb_substr($excerpt, 0, 120) . (mb_strlen($excerpt) > 120 ? '...' : ''), $isCli);

    if ($dryRun) {
        $results[] = ['post_id' => $postId, 'status' => 'dry_run', 'excerpt' => $excerpt];
        $processed++;
        usleep(500000);
        continue;
    }

    // ── 3. Escribir excerpt en el sitio ──
    $updResp = bridgeRequest(
        $bridgeUrl . '?action=update_post_excerpt',
        $authHeader,
        ['post_id' => $postId, 'excerpt' => $excerpt]
    );

    if ($updResp['http'] >= 200 && $updResp['http'] < 300 && !empty($updResp['body']['success'])) {
        $written++;
        logLine("    ✓ Escrito en post_excerpt (ID {$postId})", $isCli);
        $results[] = ['post_id' => $postId, 'status' => 'written'];
    } else {
        $failed++;
        logLine("    ✗ Error escribiendo excerpt: HTTP {$updResp['http']} — " . substr($updResp['raw'], 0, 300), $isCli);
        $results[] = ['post_id' => $postId, 'status' => 'error', 'detail' => substr($updResp['raw'], 0, 300)];
    }

    $processed++;
    usleep(500000); // 0.5 s de pausa entre posts (igual al script de referencia)
}

// ── Resumen ──
$summary = "Procesados: {$processed} | Generados: {$generated} | " .
           ($dryRun ? "(dry-run) " : "Escritos: {$written} | ") .
           "Omitidos: {$skipped} | Fallidos: {$failed}";

logLine(PHP_EOL . str_repeat('─', 60), $isCli);
logLine("RESUMEN: " . $summary, $isCli);

respond('ok', $summary, [
    'processed' => $processed,
    'generated' => $generated,
    'written'   => $written,
    'skipped'   => $skipped,
    'failed'    => $failed,
    'results'   => $results,
], $isCli);
