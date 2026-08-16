<?php
/**
 * verify_alt.php
 *
 * VERIFICACIÓN INDEPENDIENTE del backfill de alt text.
 *
 * No llama a DeepSeek ni escribe nada: solo consulta list_comics (que lee
 * _wp_attachment_image_alt directamente de la BD de WordPress) y cuenta:
 *   - attachments con alt válido (no débil)
 *   - attachments con alt débil/vacío (fallo real o pendiente)
 *   - cómics sin imágenes
 *
 * USO:
 *   php verify_alt.php            → barre TODO el catálogo en lotes de 100
 *   php verify_alt.php --max 5    → solo los primeros N cómics (prueba rápida)
 *
 * @package ComicScraperPro
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(0);
ignore_user_abort(true);

$isCli = (PHP_SAPI === 'cli');

// ── Credenciales ──
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    fwrite(STDERR, "No se encontró config.json\n");
    exit(1);
}
$cfg = json_decode(file_get_contents($configFile), true) ?: [];
$baseUrl  = rtrim($cfg['wp_base_url'] ?? '', '/');
$username = $cfg['wp_username'] ?? '';
$password = $cfg['wp_app_password'] ?? '';
if (empty($baseUrl) || empty($username) || empty($password)) {
    fwrite(STDERR, "Faltan credenciales de WordPress en config.json\n");
    exit(1);
}

$authHeader = 'Authorization: Basic ' . base64_encode("{$username}:{$password}");
$bridgeUrl  = $baseUrl . '/wp-media-bridge.php';

// ── Flags ──
$max = 0;
if ($isCli) {
    $maxIdx = array_search('--max', $argv, true);
    if ($maxIdx !== false && isset($argv[$maxIdx + 1])) {
        $max = max(0, (int) $argv[$maxIdx + 1]);
    }
}

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
 * Clasifica un alt text en: 'valid', 'empty' (fallo real de escritura) o
 * 'legacy' (patrón numérico antiguo, ej. "713623 ...").
 *
 * NOTA: el patrón "descripción - Página N" que genera el backfill ES válido
 * (base descriptiva + sufijo de página), por lo que NO se considera débil.
 */
function classifyAlt(string $alt): string
{
    $alt = trim($alt);
    if ($alt === '') {
        return 'empty';
    }
    if (preg_match('/^\d+\s/', $alt)) {
        return 'legacy';
    }
    return 'valid';
}

// ── Barrido ──
$offset = 0;
$batch = 100;
$totalComics    = 0;
$totalAtt       = 0;
$attValid       = 0;
$attEmpty       = 0; // fallo real de escritura (alt vacío)
$attLegacy      = 0; // patrón numérico antiguo
$comicsSinImg   = 0;
$comicsWithEmpty = 0;
$emptyExamples  = []; // [comic_id, attachment_id] para diagnóstico
$legacyExamples = []; // [comic_id, attachment_id, alt] para diagnóstico

while (true) {
    $resp = bridgeRequest(
        $bridgeUrl . '?action=list_comics',
        $authHeader,
        ['per_page' => $batch, 'offset' => $offset]
    );

    if ($resp['http'] < 200 || $resp['http'] >= 300 || empty($resp['body']['success'])) {
        fwrite(STDERR, "ERROR list_comics offset={$offset}: HTTP {$resp['http']} — " . substr($resp['raw'], 0, 300) . "\n");
        exit(1);
    }

    $comics = $resp['body']['comics'] ?? [];
    if (empty($comics)) {
        break;
    }

    foreach ($comics as $comic) {
        if ($max > 0 && $totalComics >= $max) {
            break 2;
        }
        $totalComics++;
        $cid = (int) ($comic['id'] ?? 0);
        $atts = $comic['attachments'] ?? [];

        if (empty($atts)) {
            $comicsSinImg++;
            continue;
        }

        $hasEmpty = false;
        foreach ($atts as $att) {
            $totalAtt++;
            $aid = (int) ($att['id'] ?? 0);
            $alt = (string) ($att['alt'] ?? '');
            $class = classifyAlt($alt);
            if ($class === 'empty') {
                $attEmpty++;
                $hasEmpty = true;
                if (count($emptyExamples) < 50) {
                    $emptyExamples[] = ['comic_id' => $cid, 'attachment_id' => $aid];
                }
            } elseif ($class === 'legacy') {
                $attLegacy++;
                if (count($legacyExamples) < 50) {
                    $legacyExamples[] = ['comic_id' => $cid, 'attachment_id' => $aid, 'alt' => $alt];
                }
            } else {
                $attValid++;
            }
        }
        if ($hasEmpty) {
            $comicsWithEmpty++;
        }
    }

    echo "Lote offset={$offset}: " . count($comics) . " cómics revisados\n";

    $offset += count($comics);
    if (count($comics) < $batch) {
        break;
    }
    usleep(200000);
}

echo str_repeat('─', 60) . "\n";
echo "RESUMEN DE VERIFICACIÓN (lectura directa de la BD vía list_comics):\n";
echo "  Cómics totales revisados   : {$totalComics}\n";
echo "  Attachments totales        : {$totalAtt}\n";
echo "  Attachments alt VÁLIDO     : {$attValid}\n";
echo "  Attachments alt VACÍO      : {$attEmpty}   ← fallo real de escritura\n";
echo "  Attachments alt LEGACY     : {$attLegacy}   ← patrón numérico antiguo\n";
echo "  Cómics sin imágenes        : {$comicsSinImg}\n";
echo "  Cómics con ≥1 alt vacío    : {$comicsWithEmpty}\n";

if (!empty($emptyExamples)) {
    echo "\nAttachments con alt VACÍO (hasta 50):\n";
    foreach ($emptyExamples as $e) {
        echo "  cómic {$e['comic_id']} / att {$e['attachment_id']}\n";
    }
}

if (!empty($legacyExamples)) {
    echo "\nAttachments con alt LEGACY (hasta 50):\n";
    foreach ($legacyExamples as $l) {
        echo "  cómic {$l['comic_id']} / att {$l['attachment_id']}: «" . $l['alt'] . "»\n";
    }
}

$pct = $totalAtt > 0 ? round($attValid / $totalAtt * 100, 2) : 0;
echo "\nCobertura de alt válido: {$pct}%\n";
