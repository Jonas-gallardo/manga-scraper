#!/usr/bin/env php
<?php
/**
 * fix_missing_taxonomies.php
 *
 * Script CLI para reparar cómics publicados en WordPress que quedaron sin
 * taxonomías (personajes, autores, etiquetas, universos, etc.) debido al bug
 * de LiteSpeed que borraba el header Authorization en peticiones REST API.
 *
 * Estrategia:
 *   1. Consulta la DB local: todos los cómics con wp_post_id IS NOT NULL
 *      AND taxonomias IS NOT NULL AND wp_publish_status = 'published'
 *   2. Para cada cómic, parsea el JSON de taxonomías locales
 *   3. Sincroniza cada término vía bridge (ensureTermViaBridge → ensure_term)
 *   4. Asigna todos los IDs al post vía bridge (setPostTermsViaBridge → set_post_terms)
 *   5. wp_set_post_terms con append=false es idempotente: no duplica términos
 *
 * Modos:
 *   --dry-run   Previsualiza qué taxonomías se asignarían (sin llamar al bridge)
 *   --apply     Ejecuta la reparación real
 *
 * USO:
 *   php fix_missing_taxonomies.php --dry-run
 *   php fix_missing_taxonomies.php --apply
 *   php fix_missing_taxonomies.php --apply --limit=10   (solo 10 cómics)
 *   php fix_missing_taxonomies.php --apply --comic-id=12345  (un solo cómic)
 *
 * @package ScrapApp
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────
// 1. BOOTSTRAP
// ─────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script solo se ejecuta desde línea de comandos.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/src/WP/WPClient.php';

// ─────────────────────────────────────────────────────────────
// 2. PARSEAR ARGUMENTOS
// ─────────────────────────────────────────────────────────────

$options = getopt('', ['dry-run', 'apply', 'limit:', 'comic-id:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
USO: php fix_missing_taxonomies.php [--dry-run|--apply] [--limit=N] [--comic-id=N]

Opciones:
  --dry-run       Modo simulación: muestra qué se asignaría sin modificar WP
  --apply         Modo real: ejecuta la reparación en WordPress
  --limit=N       Procesar solo N cómics
  --comic-id=N    Procesar un solo cómic por ID
  --help          Mostrar esta ayuda

Si no se especifica --dry-run ni --apply, se usa --dry-run por defecto.

HELP;
    exit(0);
}

$isDryRun = isset($options['dry-run']) || !isset($options['apply']);
$limit    = isset($options['limit']) ? (int) $options['limit'] : 0;
$comicId  = isset($options['comic-id']) ? (int) $options['comic-id'] : 0;

// ─────────────────────────────────────────────────────────────
// 3. CONFIGURACIÓN WP
// ─────────────────────────────────────────────────────────────

function getWPConfig(): ?array
{
    $configFile = __DIR__ . '/config.json';
    if (!file_exists($configFile)) {
        return null;
    }
    $json = json_decode(file_get_contents($configFile), true) ?: [];
    $baseUrl  = $json['wp_base_url'] ?? 'http://localhost:10003';
    $username = $json['wp_username'] ?? 'admin';
    $password = $json['wp_app_password'] ?? '';
    if (empty($baseUrl) || empty($username) || empty($password)) {
        return null;
    }
    return [
        'base_url' => rtrim($baseUrl, '/'),
        'username' => $username,
        'password' => $password,
    ];
}

$wpConfig = getWPConfig();
if ($wpConfig === null && !$isDryRun) {
    fwrite(STDERR, "ERROR: No se encontró configuración de WordPress en config.json\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────
// 4. MAPEO DE TAXONOMÍAS (local JSON key → WP taxonomy slug)
// ─────────────────────────────────────────────────────────────

const TAXONOMY_MAP = [
    'etiquetas'  => 'post_tag',
    'universos'  => 'universo',
    'idioma'     => 'idioma',
    'tipos'      => 'tipo',
    'autores'    => 'autor',
    'personajes' => 'personaje',
];

// Keys en el JSON local que son arrays (todos menos idioma que es string)
const ARRAY_TAX_KEYS = ['universos', 'tipos', 'autores', 'personajes', 'etiquetas'];
const SINGLE_TAX_KEYS = ['idioma'];

// ─────────────────────────────────────────────────────────────
// 5. FUNCIONES AUXILIARES
// ─────────────────────────────────────────────────────────────

/**
 * Aplica rate limiting entre llamadas a la API.
 */
function rateLimit(): void
{
    static $lastCall = 0.0;
    $now = microtime(true);
    $elapsed = $now - $lastCall;
    $minGap = PUBLISH_RATE_LIMIT_SECONDS;
    if ($lastCall > 0 && $elapsed < $minGap) {
        usleep((int) (($minGap - $elapsed) * 1_000_000));
    }
    $lastCall = microtime(true);
}

/**
 * Formatea una lista de strings para mostrar en consola.
 */
function formatList(array $items, int $max = 10): string
{
    if (empty($items)) {
        return '(ninguno)';
    }
    $count = count($items);
    $shown = array_slice($items, 0, $max);
    $str = implode(', ', $shown);
    if ($count > $max) {
        $str .= " … (+" . ($count - $max) . " más)";
    }
    return $str;
}

// ─────────────────────────────────────────────────────────────
// 6. CONSULTAR CÓMICS A REPARAR
// ─────────────────────────────────────────────────────────────

$sql = "SELECT id_fuente, titulo, taxonomias, wp_post_id, wp_publish_status, universo
        FROM comics_descargados
        WHERE wp_post_id IS NOT NULL
          AND taxonomias IS NOT NULL
          AND wp_publish_status = 'published'";

$params = [];

if ($comicId > 0) {
    $sql .= " AND id_fuente = ?";
    $params[] = $comicId;
}

$sql .= " ORDER BY id_fuente ASC";

if ($limit > 0) {
    $sql .= " LIMIT ?";
    $params[] = $limit;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$comics = $stmt->fetchAll();

$totalComics = count($comics);

if ($totalComics === 0) {
    echo "No se encontraron cómics publicados con taxonomías para reparar.\n";
    echo "NOTA: Solo se procesan cómics con wp_publish_status = 'published'.\n";
    exit(0);
}

echo str_repeat('═', 70) . "\n";
echo "  REPARADOR DE TAXONOMÍAS FALTANTES\n";
echo str_repeat('═', 70) . "\n";
echo "Modo:       " . ($isDryRun ? "SIMULACIÓN (--dry-run)" : "REAL (--apply)") . "\n";
echo "Cómics:     {$totalComics}\n";
if ($comicId > 0) {
    echo "ID:         {$comicId}\n";
}
if ($limit > 0) {
    echo "Límite:     {$limit}\n";
}
echo str_repeat('─', 70) . "\n\n";

// ─────────────────────────────────────────────────────────────
// 7. PROCESAR CADA CÓMIC
// ─────────────────────────────────────────────────────────────

$wpClient = null;
if (!$isDryRun) {
    $wpClient = new WPClient(
        $wpConfig['base_url'],
        $wpConfig['username'],
        $wpConfig['password'],
        ['timeout' => 60]
    );
}

$stats = [
    'total'      => $totalComics,
    'procesados' => 0,
    'exitosos'   => 0,
    'errores'    => 0,
    'omitidos'   => 0,
    'terms_synced'  => 0,
    'terms_existing' => 0,
    'detalle'    => [],
];

$startTime = microtime(true);

foreach ($comics as $index => $comic) {
    $idFuente  = (int) $comic['id_fuente'];
    $titulo    = $comic['titulo'];
    $wpPostId  = (int) $comic['wp_post_id'];
    $universo  = $comic['universo'] ?? '?';

    echo sprintf("[%d/%d] #%d | %s | WP Post ID: %d\n", $index + 1, $totalComics, $idFuente, $titulo, $wpPostId);

    // Parsear JSON de taxonomías
    $taxJson = $comic['taxonomias'];
    if (is_string($taxJson)) {
        $taxData = json_decode($taxJson, true);
    } else {
        // Puede venir como objeto JSON de MySQL
        $taxData = is_array($taxJson) ? $taxJson : json_decode((string) $taxJson, true);
    }

    if (!is_array($taxData) || empty($taxData)) {
        echo "  ⚠️  Taxonomías vacías o inválidas — omitido\n\n";
        $stats['omitidos']++;
        $stats['detalle'][] = ['id' => $idFuente, 'titulo' => $titulo, 'resultado' => 'omitido', 'razon' => 'taxonomías vacías'];
        continue;
    }

    $totalTermsLocal = 0;
    foreach (TAXONOMY_MAP as $localKey => $wpSlug) {
        if ($localKey === 'idioma') {
            if (!empty($taxData[$localKey])) $totalTermsLocal++;
        } elseif (!empty($taxData[$localKey]) && is_array($taxData[$localKey])) {
            $totalTermsLocal += count($taxData[$localKey]);
        }
    }

    if ($totalTermsLocal === 0) {
        echo "  ⚠️  Sin términos en el JSON — omitido\n\n";
        $stats['omitidos']++;
        $stats['detalle'][] = ['id' => $idFuente, 'titulo' => $titulo, 'resultado' => 'omitido', 'razon' => 'sin términos'];
        continue;
    }

    // ── Mostrar taxonomías que se asignarán ──
    echo "  Universo:  {$universo}\n";
    echo "  Términos a sincronizar: {$totalTermsLocal}\n";

    foreach (TAXONOMY_MAP as $localKey => $wpSlug) {
        $raw = $taxData[$localKey] ?? null;
        if (empty($raw)) continue;

        if ($localKey === 'idioma') {
            echo "    {$wpSlug}: {$raw}\n";
        } elseif (is_array($raw)) {
            echo "    {$wpSlug} (" . count($raw) . "): " . formatList($raw) . "\n";
        }
    }

    if ($isDryRun) {
        echo "  🟡 DRY-RUN: no se modificó WordPress\n\n";
        $stats['procesados']++;
        continue;
    }

    // ── MODO REAL: sincronizar términos y asignar al post ──
    try {
        $syncedIds = []; // [wpSlug => [id, ...]]

        foreach (TAXONOMY_MAP as $localKey => $wpSlug) {
            $raw = $taxData[$localKey] ?? null;
            if (empty($raw)) continue;

            $terms = ($localKey === 'idioma') ? [$raw] : (is_array($raw) ? $raw : [$raw]);

            foreach ($terms as $termName) {
                $termName = trim((string) $termName);
                if ($termName === '') continue;

                rateLimit();
                try {
                    $termId = $wpClient->ensureTermViaBridge($wpSlug, $termName);
                    if (!isset($syncedIds[$wpSlug])) {
                        $syncedIds[$wpSlug] = [];
                    }
                    if (!in_array($termId, $syncedIds[$wpSlug], true)) {
                        $syncedIds[$wpSlug][] = $termId;
                    }
                    $stats['terms_synced']++;
                } catch (RuntimeException $e) {
                    echo "  ⚠️  Error sincronizando '{$termName}' en '{$wpSlug}': " . $e->getMessage() . "\n";
                    // Continuar con los demás términos
                }
            }
        }

        if (empty($syncedIds)) {
            echo "  ⚠️  No se pudo sincronizar ningún término — omitido\n\n";
            $stats['omitidos']++;
            $stats['detalle'][] = ['id' => $idFuente, 'titulo' => $titulo, 'resultado' => 'omitido', 'razon' => 'falló la sincronización de todos los términos'];
            continue;
        }

        // ── Asignar taxonomías al post vía bridge ──
        rateLimit();
        $result = $wpClient->setPostTermsViaBridge($wpPostId, $syncedIds);

        echo "  ✅ Asignado: " . json_encode($syncedIds, JSON_UNESCAPED_UNICODE) . "\n";
        if (isset($result['results'])) {
            foreach ($result['results'] as $tax => $r) {
                if (isset($r['assigned'])) {
                    echo "     {$tax}: {$r['assigned']} término(s)\n";
                } elseif (isset($r['error'])) {
                    echo "     {$tax}: ERROR — {$r['error']}\n";
                }
            }
        }

        $stats['exitosos']++;
        $stats['detalle'][] = [
            'id'        => $idFuente,
            'titulo'    => $titulo,
            'wp_post_id' => $wpPostId,
            'resultado' => 'exitoso',
            'asignado'  => $syncedIds,
        ];

    } catch (RuntimeException $e) {
        echo "  ❌ ERROR: " . $e->getMessage() . "\n";
        $stats['errores']++;
        $stats['detalle'][] = ['id' => $idFuente, 'titulo' => $titulo, 'resultado' => 'error', 'razon' => $e->getMessage()];
    }

    echo "\n";
    $stats['procesados']++;
}

// ─────────────────────────────────────────────────────────────
// 8. RESUMEN FINAL
// ─────────────────────────────────────────────────────────────

$elapsed = round(microtime(true) - $startTime, 2);

echo str_repeat('═', 70) . "\n";
echo "  RESUMEN\n";
echo str_repeat('═', 70) . "\n";
echo "Modo:         " . ($isDryRun ? "SIMULACIÓN" : "REAL") . "\n";
echo "Total:        {$stats['total']}\n";
echo "Procesados:   {$stats['procesados']}\n";
echo "Exitosos:     {$stats['exitosos']}\n";
echo "Errores:      {$stats['errores']}\n";
echo "Omitidos:     {$stats['omitidos']}\n";

if (!$isDryRun) {
    echo "Términos:     {$stats['terms_synced']} sincronizados\n";
}

echo "Tiempo:       {$elapsed}s\n";
echo str_repeat('─', 70) . "\n";

// ── Mostrar listado de errores ──
if ($stats['errores'] > 0) {
    echo "\n⚠️  CÓMICS CON ERROR:\n";
    foreach ($stats['detalle'] as $d) {
        if ($d['resultado'] === 'error') {
            echo "  #{$d['id']} | {$d['titulo']} → {$d['razon']}\n";
        }
    }
}

if ($stats['omitidos'] > 0) {
    echo "\n⚠️  CÓMICS OMITIDOS:\n";
    foreach ($stats['detalle'] as $d) {
        if ($d['resultado'] === 'omitido') {
            echo "  #{$d['id']} | {$d['titulo']} → {$d['razon']}\n";
        }
    }
}

if ($isDryRun && $stats['procesados'] > 0) {
    echo "\n💡 Para ejecutar la reparación real, usa: php fix_missing_taxonomies.php --apply\n";
}

echo "\n";
exit($stats['errores'] > 0 ? 1 : 0);
