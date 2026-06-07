<?php
/**
 * harvest_series.php
 *
 * Recolecta todas las series/universos del listado de 3hentai.net (18 páginas)
 * y genera un reporte cruzado contra la lista de universos existentes.
 *
 * CLASIFICACIÓN:
 *
 *   COINCIDENCIA EXACTA     → El nombre coincide exactamente con un universo existente
 *   COINCIDENCIA FUZZY      → Match por similitud (similar_text ≥ 75%)
 *   COINCIDENCIA POR SLUG   → El slug de la URL coincide
 *   SIN COINCIDENCIA        → No existe en la lista de universos (nuevo o muy diferente)
 *
 * USO:
 *   php harvest_series.php                  → Escanea todas las páginas y muestra reporte
 *   php harvest_series.php --save=series.json → Guarda el listado completo en JSON
 */

require_once __DIR__ . '/includes/TaxonomyData.php';
require_once __DIR__ . '/includes/UniverseProcessor.php';

// ── Configuración ──
define('BASE_URL', 'https://es.3hentai.net/series');
define('MAX_PAGES', 18);
define('USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// ── Parsear argumentos ──
$saveFile = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--save=(.+)$/', $arg, $m)) {
        $saveFile = $m[1];
    }
}

// ── Convertir data-qty a número ──
function parseCount(string $qty): int {
    $qty = trim(strtolower($qty));
    if ($qty === '' || $qty === '0') return 0;
    
    $multiplier = 1;
    if (str_ends_with($qty, 'k')) {
        $multiplier = 1000;
        $qty = substr($qty, 0, -1);
    } elseif (str_ends_with($qty, 'm')) {
        $multiplier = 1000000;
        $qty = substr($qty, 0, -1);
    }
    
    if (str_contains($qty, '.')) {
        return (int) ((float) $qty * $multiplier);
    }
    
    return (int) $qty * $multiplier;
}

// ── Convertir slug de URL a nombre legible ──
function slugToName(string $slug): string {
    // Extraer el último segmento de la URL
    $slug = preg_replace('/^.*\/series\//', '', $slug);
    $slug = preg_replace('/^.*\//', '', $slug);
    // Reemplazar guiones por espacios
    $name = str_replace('-', ' ', $slug);
    // Limpiar
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return $name;
}

// ── Obtener HTML de una página ──
function fetchPage(int $page): ?string {
    $url = $page === 1 ? BASE_URL : BASE_URL . '?page=' . $page;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: es-ES,es;q=0.9,en;q=0.8',
        ],
    ]);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($html === false || $html === '') {
        echo "  ⚠️  Error página {$page}: " . ($error ?: "HTTP {$httpCode}") . "\n";
        return null;
    }
    
    echo "  📄 Página {$page} obtenida (HTTP {$httpCode}, " . strlen($html) . " bytes)\n";
    return $html;
}

// ── Extraer series de una página ──
function extractSeries(string $html): array {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($doc);
    
    $series = [];
    
    // Buscar <a class="name"> dentro del contenedor tag-listing-container
    $nodes = $xpath->query("//div[contains(@class, 'tag-listing-container')]//a[contains(@class, 'name')]");
    
    if ($nodes === false || $nodes->length === 0) {
        $nodes = $xpath->query("//a[contains(@class, 'name')]");
    }
    
    foreach ($nodes as $node) {
        $displayName = trim($node->textContent);
        $qtyRaw = $node->getAttribute('data-qty');
        $qty = parseCount($qtyRaw);
        $href = $node->getAttribute('href');
        $slugName = slugToName($href);
        
        if ($displayName === '') continue;
        
        $series[] = [
            'display_name' => $displayName,
            'slug_name'    => $slugName,
            'count_raw'    => $qtyRaw,
            'count'        => $qty,
            'url'          => $href,
        ];
    }
    
    return $series;
}

// ── Cargar universos existentes ──
function loadExistingUniverses(): array {
    $universes = TaxonomyData::getUniverses();
    $normalized = TaxonomyData::getUniversesNormalized();
    
    // También construir normalized → original
    $reverse = [];
    foreach ($universes as $univ) {
        $key = TaxonomyData::normalizeForSearch($univ);
        $reverse[$key] = $univ;
    }
    
    return [$universes, $normalized, $reverse];
}

// ── Clasificar serie contra universos existentes ──
function classifySeries(string $displayName, string $slugName, array $reverseNormalized, UniverseProcessor $processor): array {
    $displayLower = mb_strtolower(trim($displayName), 'UTF-8');
    $slugLower = mb_strtolower(trim($slugName), 'UTF-8');
    
    // Preparar candidatos para matching: nombre mostrado y sus alias (separados por |)
    $candidates = preg_split('/\s*\|\s*/', $displayLower);
    
    // También agregar el slug como candidato
    $candidates[] = $slugLower;
    $candidates = array_unique(array_filter(array_map('trim', $candidates)));
    
    $bestResult = null;
    $bestScore = 0.0;
    
    foreach ($candidates as $candidate) {
        $searchKey = TaxonomyData::normalizeForSearch($candidate);
        
        // ── Validación: ignorar candidatos muy cortos (1-2 caracteres) ──
        if (strlen($searchKey) < 3) {
            continue;
        }
        
        // 1. Coincidencia exacta normalizada
        if (isset($reverseNormalized[$searchKey])) {
            $matched = $reverseNormalized[$searchKey];
            $score = 100;
            if ($bestResult === null || $score > $bestScore) {
                $bestResult = [
                    'status'       => 'COINCIDENCIA EXACTA',
                    'matched'      => $matched,
                    'matched_key'  => $candidate,
                    'score'        => $score,
                ];
                $bestScore = $score;
            }
            continue;
        }
        
        // 2. Usar el UniverseProcessor para fuzzy matching
        $processed = $processor->process($candidate);
        if ($processed !== null) {
            // Verificar si el procesado coincide con algún universo existente
            $procKey = TaxonomyData::normalizeForSearch($processed);
            if (isset($reverseNormalized[$procKey])) {
                $matched = $reverseNormalized[$procKey];
                
                // Calcular score de similitud
                similar_text($searchKey, $procKey, $score);
                $score = $score / 100.0;
                
                // Bonus por substring (solo si hay similitud base ≥ 60%)
                if ($score < 1.0 && $score >= 0.60) {
                    if (strpos($procKey, $searchKey) !== false || strpos($searchKey, $procKey) !== false) {
                        $score = max($score, 0.85);
                    }
                }
                
                // Umbral mínimo dinámico para fuzzy
                $minFuzzyScore = 82.0; // 82%
                if (strlen($searchKey) < 5) {
                    $minFuzzyScore = 90.0; // 90% para strings cortos
                }
                
                if ($score * 100 >= $minFuzzyScore) {
                    if ($bestResult === null || $score * 100 > $bestScore) {
                        $bestResult = [
                            'status'       => $score >= 0.99 ? 'COINCIDENCIA EXACTA' : 'COINCIDENCIA FUZZY',
                            'matched'      => $matched,
                            'matched_key'  => $candidate,
                            'score'        => round($score * 100, 1),
                        ];
                        $bestScore = $score * 100;
                    }
                }
            }
        }
    }
    
    if ($bestResult !== null) {
        return $bestResult;
    }
    
    return [
        'status'       => 'SIN COINCIDENCIA',
        'matched'      => null,
        'matched_key'  => null,
        'score'        => 0,
    ];
}

// ═══════════════════════════════════════
//  MAIN
// ═══════════════════════════════════════

echo "=== RECOLECTOR DE SERIES/UNIVERSOS DE 3HENTAI.NET ===\n\n";

// Cargar universos existentes
[$universes, $normalized, $reverseNormalized] = loadExistingUniverses();
$processor = new UniverseProcessor();

echo "Universos existentes en WordPress: " . count($universes) . "\n\n";

// Escanear páginas
echo "Páginas a escanear: 1-" . MAX_PAGES . "\n\n";

$allSeries = [];
$pageErrors = 0;

for ($page = 1; $page <= MAX_PAGES; $page++) {
    $html = fetchPage($page);
    if ($html === null) {
        $pageErrors++;
        continue;
    }
    
    $series = extractSeries($html);
    echo "    → {$page}: " . count($series) . " series encontradas\n";
    
    foreach ($series as $s) {
        $key = $s['display_name'] . '|' . $s['slug_name'];
        if (!isset($allSeries[$key])) {
            $allSeries[$key] = $s;
        }
    }
    
    usleep(500000); // 0.5 segundos entre páginas
}

echo "\n";
echo "═══ RESULTADOS CRUDOS ═══\n";
echo "Total series/universos únicos recolectados: " . count($allSeries) . "\n";
echo "Páginas con error: {$pageErrors}\n\n";

// Clasificar cada serie
$classified = [];
foreach ($allSeries as $key => $info) {
    $classification = classifySeries(
        $info['display_name'],
        $info['slug_name'],
        $reverseNormalized,
        $processor
    );
    
    $classified[] = [
        'display_name' => $info['display_name'],
        'slug_name'    => $info['slug_name'],
        'count'        => $info['count'],
        'count_raw'    => $info['count_raw'],
        'url'          => $info['url'],
        'status'       => $classification['status'],
        'matched'      => $classification['matched'],
        'matched_key'  => $classification['matched_key'],
        'score'        => $classification['score'],
    ];
}

// Ordenar: primero los que coinciden, luego por cantidad descendente
usort($classified, function ($a, $b) {
    $order = ['COINCIDENCIA EXACTA' => 0, 'COINCIDENCIA FUZZY' => 1, 'SIN COINCIDENCIA' => 2];
    $aOrder = $order[$a['status']] ?? 3;
    $bOrder = $order[$b['status']] ?? 3;
    if ($aOrder !== $bOrder) return $aOrder - $bOrder;
    return $b['count'] - $a['count'];
});

// ── Reporte ──
echo "═══ REPORTE COMPLETO ═══\n\n";

$counts = [
    'COINCIDENCIA EXACTA' => 0,
    'COINCIDENCIA FUZZY'  => 0,
    'SIN COINCIDENCIA'    => 0,
];

foreach ($classified as $c) {
    $counts[$c['status']]++;
}

echo "RESUMEN:\n";
foreach ($counts as $status => $cnt) {
    $pct = round($cnt / count($classified) * 100, 1);
    echo "  {$status}: {$cnt} ({$pct}%)\n";
}
echo "\n";

// ── Universos existentes y su estado ──
echo "═══ UNIVERSOS EXISTENTES EN WORDPRESS ═══\n\n";
foreach ($universes as $univ) {
    // Buscar si alguna serie de 3hentai matchea este universo
    $found = false;
    foreach ($classified as $c) {
        if ($c['matched'] !== null && strcasecmp($c['matched'], $univ) === 0) {
            $found = true;
            echo sprintf(
                "  ✅ %-40s ← \"%s\" (%s)\n",
                $univ,
                $c['display_name'],
                $c['count_raw']
            );
            break;
        }
    }
    if (!$found) {
        echo "  ⚠️  {$univ} — NO ENCONTRADO en 3hentai.net\n";
    }
}

// ── Series SIN coincidencia (nuevas) ──
$unmatched = array_filter($classified, fn($c) => $c['status'] === 'SIN COINCIDENCIA');
// Ordenar por cantidad descendente
usort($unmatched, fn($a, $b) => $b['count'] - $a['count']);

echo "\n═══ SERIES SIN COINCIDENCIA (NUEVAS - ordenadas por popularidad) ═══\n\n";

// Agrupar por rango de popularidad
$ranges = [
    '🟣 MÁS DE 10,000' => [],
    '🔵 1,000 - 9,999' => [],
    '🟢 100 - 999'     => [],
    '🟡 1 - 99'        => [],
    '⚫ 0 resultados'  => [],
];

foreach ($unmatched as $c) {
    if ($c['count'] >= 10000) $ranges['🟣 MÁS DE 10,000'][] = $c;
    elseif ($c['count'] >= 1000) $ranges['🔵 1,000 - 9,999'][] = $c;
    elseif ($c['count'] >= 100) $ranges['🟢 100 - 999'][] = $c;
    elseif ($c['count'] >= 1) $ranges['🟡 1 - 99'][] = $c;
    else $ranges['⚫ 0 resultados'][] = $c;
}

foreach ($ranges as $label => $items) {
    if (empty($items)) continue;
    echo "\n--- {$label} (" . count($items) . ") ---\n";
    $shown = 0;
    foreach ($items as $c) {
        if ($shown >= 30) {
            echo "  ... y " . (count($items) - 30) . " más\n";
            break;
        }
        echo sprintf(
            "  [%8s] %s\n",
            $c['count_raw'],
            $c['display_name']
        );
        $shown++;
    }
}

// ── Coincidencias fuzzy (para revisar si son correctas) ──
$fuzzy = array_filter($classified, fn($c) => $c['status'] === 'COINCIDENCIA FUZZY');
if (!empty($fuzzy)) {
    echo "\n\n═══ COINCIDENCIAS DIFUSAS (REVISAR) ═══\n\n";
    foreach ($fuzzy as $c) {
        echo sprintf(
            "  [%8s] %-45s → %s  (score: %s%%)\n",
            $c['count_raw'],
            $c['display_name'],
            $c['matched'],
            $c['score']
        );
    }
}

// ── Guardar a archivo si se solicitó ──
if ($saveFile) {
    $export = [
        'generated_at' => date('Y-m-d H:i:s'),
        'total_series' => count($classified),
        'summary'      => $counts,
        'series'       => $classified,
    ];
    file_put_contents($saveFile, json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "\n\n📁 Listado completo guardado en: {$saveFile}\n";
}

echo "\n=== FIN ===\n";
