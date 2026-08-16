<?php
/**
 * audit_backfill.php
 *
 * Auditoría de la compensación: consulta TODOS los posts publicados en
 * producción vía el puente wp-media-bridge.php y reporta:
 *   - Total de posts publicados
 *   - Posts con excerpt NO vacío (compensación aplicada)
 *   - Posts con excerpt VACÍO (fallidos o pendientes)
 *   - Posts con caracteres prohibidos (markdown) en el excerpt
 *
 * USO:
 *   php audit_backfill.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

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

function bridgeGet(string $url, string $authHeader, array $payload): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            $authHeader,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => 'ComicScraperPro/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

$total       = 0;
$filled      = 0;
$emptyIds    = [];
$prohibited  = [];

$offset = 0;
$perPage = 100;

while (true) {
    $resp = bridgeGet($bridgeUrl . '?action=list_posts', $authHeader, [
        'post_type' => 'post',
        'per_page'  => $perPage,
        'offset'    => $offset,
    ]);

    if ($resp === null || empty($resp['posts'])) {
        break;
    }

    foreach ($resp['posts'] as $post) {
        $total++;
        $id = (int) ($post['id'] ?? 0);
        $excerpt = trim((string) ($post['excerpt'] ?? ''));

        if ($excerpt === '') {
            $emptyIds[] = $id;
            continue;
        }

        $filled++;

        // Detectar caracteres prohibidos (markdown)
        if (preg_match('/[*_`\[\]{}<>#]/u', $excerpt)) {
            $prohibited[] = ['id' => $id, 'excerpt' => $excerpt];
        }
    }

    if (count($resp['posts']) < $perPage) {
        break;
    }
    $offset += $perPage;

    fwrite(STDOUT, "  ... offset {$offset} consultado (total acumulado: {$total})\n");
    usleep(200000);
}

fwrite(STDOUT, "\n" . str_repeat('=', 60) . "\n");
fwrite(STDOUT, "RESULTADO DE AUDITORÍA (producción)\n");
fwrite(STDOUT, str_repeat('=', 60) . "\n");
fwrite(STDOUT, "Total de posts publicados:        {$total}\n");
fwrite(STDOUT, "Posts con excerpt (compensados):  {$filled}\n");
fwrite(STDOUT, "Posts con excerpt VACÍO:          " . count($emptyIds) . "\n");
fwrite(STDOUT, "Posts con caracteres prohibidos:  " . count($prohibited) . "\n");

if (!empty($emptyIds)) {
    fwrite(STDOUT, "\nIDs con excerpt vacío (fallidos/pendientes):\n");
    fwrite(STDOUT, "  " . implode(', ', $emptyIds) . "\n");
}

if (!empty($prohibited)) {
    fwrite(STDOUT, "\nPosts con caracteres prohibidos:\n");
    foreach ($prohibited as $p) {
        fwrite(STDOUT, "  ID {$p['id']}: " . mb_substr($p['excerpt'], 0, 140) . "\n");
    }
}

fwrite(STDOUT, "\nAuditoría completada.\n");
