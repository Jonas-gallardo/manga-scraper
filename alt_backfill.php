<?php
/**
 * alt_backfill.php
 *
 * FASE ALT TEXT — Compensación (backfill) del texto alternativo de imágenes.
 *
 * Consulta los cómics publicados DIRECTAMENTE desde el sitio WordPress
 * (gluglux.com) vía el puente wp-media-bridge.php (acción list_comics),
 * genera UNA descripción base por cómic con DeepSeek y escribe el alt text
 * de cada página del cómic vía la acción set_attachment_alt.
 *
 * Estrategia (una sola llamada DeepSeek por cómic, no por imagen):
 *   - Portada (primer attachment):  descripción base completa
 *   - Página N (N >= 2):            "descripción base - Página N"
 *
 * USO:
 *   php alt_backfill.php                         → lote inicial (offset 0, 50 cómics)
 *   php alt_backfill.php 0 50                    → offset y cantidad
 *   php alt_backfill.php 0 50 --dry-run          → sin escribir, solo generar y mostrar
 *   php alt_backfill.php 0 50 --only-empty       → solo cómics con alt vacío/débil
 *   php alt_backfill.php 0 50 --force            → sobrescribe alt existentes
 *   php alt_backfill.php --all                   → Itera TODO el catálogo en lotes de 100
 *   php alt_backfill.php --all --only-empty      → idem, solo cómics con alt vacío/débil
 *   php alt_backfill.php --all --only-empty --max 10 --dry-run
 *                                                → prueba: como máximo 10 cómics, sin escribir
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
    $all       = in_array('--all', $argv, true);
    $dryRun    = in_array('--dry-run', $argv, true);
    $onlyEmpty = in_array('--only-empty', $argv, true);
    $force     = in_array('--force', $argv, true);

    // Parsear flags y capturar SOLO los args posicionales numéricos (offset, limit).
    // Así `--all --only-empty --max 3 --dry-run` no confunde flags con números.
    $max        = 0;
    $positional = [];
    $args       = array_slice($argv, 1);
    for ($i = 0, $n = count($args); $i < $n; $i++) {
        $arg = $args[$i];
        if ($arg === '--max') {
            if (isset($args[$i + 1])) {
                $max = max(0, (int) $args[$i + 1]);
                $i++; // saltar el valor
            }
            continue;
        }
        // Solo los tokens estrictamente numéricos son offset/limit.
        if (preg_match('/^\d+$/', $arg)) {
            $positional[] = (int) $arg;
        }
    }

    $offset = $positional[0] ?? 0;
    // En modo --all el lote por defecto es 100 (máximo soportado por el bridge).
    $limit  = isset($positional[1]) ? max(1, min(100, $positional[1])) : ($all ? 100 : 50);
} else {
    $all       = !empty($_GET['all']);
    $dryRun    = !empty($_GET['dry_run']);
    $onlyEmpty = !empty($_GET['only_empty']);
    $force     = !empty($_GET['force']);
    $offset    = max(0, (int) ($_GET['offset'] ?? 0));
    $limit     = max(1, min(100, (int) ($_GET['limit'] ?? ($all ? 100 : 50))));
    $max       = max(0, (int) ($_GET['max'] ?? 0));
}

$authHeader = 'Authorization: Basic ' . base64_encode("{$username}:{$password}");
$bridgeUrl  = $baseUrl . '/wp-media-bridge.php';

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
 * Determina si un alt text es "débil" (vacío o heredado del nombre de archivo).
 * Patrones débiles: vacío, empieza por un número (ej "713623 ..."),
 * o es el patrón "Título - Página N" heredado sin valor descriptivo real.
 */
function isWeakAlt(string $alt): bool
{
    $alt = trim($alt);
    if ($alt === '') {
        return true;
    }
    // Viejo patrón de subida: empieza con dígitos (ID de archivo heredado, ej. "713623 ...")
    if (preg_match('/^\d+\s/', $alt)) {
        return true;
    }
    // NOTA: el patrón "descripción - Página N" que genera ESTE backfill es válido
    // (base descriptiva + sufijo de página), por lo que ya no se marca como débil.
    return false;
}

// ── Estado global acumulado (persiste entre lotes en modo --all) ──
$deepseek = new DeepSeekClient();
$stats = [
    'processed'           => 0, // cómics que llegaron a generar alt base (con DeepSeek)
    'generated'           => 0, // descripciones base generadas
    'attachments_written' => 0,
    'skipped'             => 0,
    'failed'              => 0,
    'batches'             => 0,
    'results'             => [],
];

$currentOffset = $offset;
$batchLimit    = $all ? min(100, max(1, $limit)) : $limit;

if ($all) {
    logLine("Modo --all: iterando TODO el catálogo en lotes de {$batchLimit} a partir del offset {$currentOffset}.", $isCli);
}

do {
    // ── 1. Obtener cómics desde el sitio ──
    logLine(PHP_EOL . "── Lote (offset={$currentOffset}, limit={$batchLimit}) ──", $isCli);

    $listResp = bridgeRequest(
        $bridgeUrl . '?action=list_comics',
        $authHeader,
        ['per_page' => $batchLimit, 'offset' => $currentOffset]
    );

    if ($listResp['http'] < 200 || $listResp['http'] >= 300 || empty($listResp['body']['success'])) {
        respond('error', 'Error consultando cómics: HTTP ' . $listResp['http'] . ' — ' . substr($listResp['raw'], 0, 500), null, $isCli);
    }

    $comics = $listResp['body']['comics'] ?? [];
    logLine("Se obtuvieron " . count($comics) . " cómics.", $isCli);

    if (empty($comics)) {
        if (!$all) {
            respond('ok', 'No hay cómics para procesar en este rango.', ['processed' => 0], $isCli);
        }
        logLine("Sin más cómics. Iteración finalizada.", $isCli);
        break;
    }

    // ── 2. Procesar cada cómic ──
    foreach ($comics as $idx => $comic) {
        // Detener tras --max cómics procesados
        if ($max > 0 && $stats['processed'] >= $max) {
            logLine("  ⏹ Límite --max {$max} alcanzado. Deteniendo.", $isCli);
            break 2; // sale del foreach y del do-while
        }

        $comicId = (int) ($comic['id'] ?? 0);
        $title   = (string) ($comic['title'] ?? '');
        $attachments = $comic['attachments'] ?? [];

        if ($comicId <= 0) {
            $stats['failed']++;
            logLine("  ✗ Cómic sin ID válido (índice {$idx})", $isCli);
            continue;
        }

        if (empty($attachments)) {
            $stats['skipped']++;
            logLine("  ⏭ Cómic ID {$comicId} «{$title}»: sin imágenes, omitido", $isCli);
            continue;
        }

        // Determinar si hay al menos un attachment con alt débil
        $hasWeak = false;
        foreach ($attachments as $att) {
            if (isWeakAlt((string) ($att['alt'] ?? ''))) {
                $hasWeak = true;
                break;
            }
        }

        if (!$force && $onlyEmpty && !$hasWeak) {
            $stats['skipped']++;
            logLine("  ⏭ Cómic ID {$comicId} «{$title}»: alt ya completos, omitido", $isCli);
            continue;
        }

        if (!$force && !$onlyEmpty && !$hasWeak) {
            $stats['skipped']++;
            logLine("  ⏭ Cómic ID {$comicId} «{$title}»: alt ya completos, omitido", $isCli);
            continue;
        }

        // ── Generar descripción base (UNA sola llamada DeepSeek por cómic) ──
        $cleanTitle = DeepSeekClient::cleanTitle($title);
        logLine("  ▶ Cómic ID {$comicId} «{$cleanTitle}» — generando alt base...", $isCli);

        // Las taxonomías no vienen en list_comics; usamos solo el título.
        // (Suficiente para un alt text descriptivo y económico.)
        $baseAlt = $deepseek->generateAltText($title);

        if ($baseAlt === null) {
            $stats['failed']++;
            logLine("    ✗ Error generando alt base: " . $deepseek->getLastError(), $isCli);
            $stats['results'][] = ['comic_id' => $comicId, 'status' => 'error', 'detail' => $deepseek->getLastError()];
            usleep(400000);
            continue;
        }

        $stats['generated']++;
        logLine("    ✓ Alt base generado: " . mb_substr($baseAlt, 0, 100) . (mb_strlen($baseAlt) > 100 ? '...' : ''), $isCli);

        // ── Construir alt por página y escribir ──
        $writtenForComic = 0;
        foreach ($attachments as $pageIndex => $att) {
            $attachmentId = (int) ($att['id'] ?? 0);
            if ($attachmentId <= 0) {
                continue;
            }

            $pageNum = $pageIndex + 1;
            // Portada: descripción completa. Páginas siguientes: base - Página N.
            $pageAlt = ($pageNum === 1)
                ? $baseAlt
                : $baseAlt . ' - Página ' . $pageNum;

            if ($dryRun) {
                logLine("      [dry-run] Attachment {$attachmentId} (página {$pageNum}): «{$pageAlt}»", $isCli);
                $stats['results'][] = ['attachment_id' => $attachmentId, 'comic_id' => $comicId, 'status' => 'dry_run', 'alt' => $pageAlt];
                continue;
            }

            $updResp = bridgeRequest(
                $bridgeUrl . '?action=set_attachment_alt',
                $authHeader,
                ['attachment_id' => $attachmentId, 'alt_text' => $pageAlt]
            );

            if ($updResp['http'] >= 200 && $updResp['http'] < 300 && !empty($updResp['body']['success'])) {
                $writtenForComic++;
                $stats['attachments_written']++;
            } else {
                logLine("      ✗ Error escribiendo alt (attachment {$attachmentId}): HTTP {$updResp['http']} — " . substr($updResp['raw'], 0, 200), $isCli);
                $stats['results'][] = ['attachment_id' => $attachmentId, 'comic_id' => $comicId, 'status' => 'error', 'detail' => substr($updResp['raw'], 0, 200)];
            }

            usleep(150000); // 0.15 s entre escrituras (evita saturar el bridge)
        }

        $stats['processed']++;
        $stats['results'][] = ['comic_id' => $comicId, 'status' => $dryRun ? 'dry_run' : 'written', 'pages_written' => $writtenForComic];
        logLine("    ✓ {$writtenForComic}/" . count($attachments) . " alt escritos", $isCli);

        usleep(400000); // 0.4 s de pausa entre cómics
    }

    $stats['batches']++;
    $currentOffset += count($comics);

    if ($all) {
        logLine("Progreso acumulado — procesados: {$stats['processed']} | generados: {$stats['generated']} | escritos: {$stats['attachments_written']} | omitidos: {$stats['skipped']} | fallidos: {$stats['failed']}", $isCli);
    }

    // Si el último lote devolvió menos de lo pedido, ya no hay más cómics.
    if ($all && count($comics) < $batchLimit) {
        logLine("Último lote incompleto: iteración finalizada.", $isCli);
        break;
    }
} while ($all);

// ── Resumen ──
$summary = "Procesados: {$stats['processed']} | Alt base generados: {$stats['generated']} | " .
           ($dryRun ? "(dry-run) " : "Attachments escritos: {$stats['attachments_written']} | ") .
           "Omitidos: {$stats['skipped']} | Fallidos: {$stats['failed']}";

logLine(PHP_EOL . str_repeat('─', 60), $isCli);
logLine("RESUMEN: " . $summary, $isCli);

respond('ok', $summary, [
    'processed'           => $stats['processed'],
    'generated'           => $stats['generated'],
    'attachments_written' => $stats['attachments_written'],
    'skipped'             => $stats['skipped'],
    'failed'              => $stats['failed'],
    'batches'             => $stats['batches'],
    'results'             => $stats['results'],
], $isCli);
