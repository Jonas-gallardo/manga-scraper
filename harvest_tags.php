<?php
/**
 * harvest_tags.php
 *
 * Recolecta todas las etiquetas del listado de 3hentai.net (22 páginas)
 * y genera un reporte cruzado contra el diccionario actual.
 *
 * Las etiquetas se clasifican en:
 *
 *   NIVEL 1 (masivas)   → >10,000 resultados
 *   NIVEL 2 (populares) → 1,000 - 9,999
 *   NIVEL 3 (comunes)   → 100 - 999
 *   NIVEL 4 (minoritarias) → 1 - 99
 *   NIVEL 0 (sin datos) → 0 resultados (se ignoran)
 *
 * USO:
 *   php harvest_tags.php                  → Escanea todas las páginas y muestra reporte
 *   php harvest_tags.php --save=tags.json → Guarda el listado completo en JSON
 *   php harvest_tags.php --page=1         → Solo una página específica
 */

require_once __DIR__ . '/includes/TaxonomyData.php';

// ── Configuración ──
define('BASE_URL', 'https://es.3hentai.net/tags');
define('MAX_PAGES', 22);
define('USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// ── Parsear argumentos ──
$saveFile = null;
$singlePage = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--save=(.+)$/', $arg, $m)) {
        $saveFile = $m[1];
    }
    if (preg_match('/^--page=(\d+)$/', $arg, $m)) {
        $singlePage = (int) $m[1];
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
    
    // Manejar decimales (ej. "1.5k")
    if (str_contains($qty, '.')) {
        return (int) ((float) $qty * $multiplier);
    }
    
    return (int) $qty * $multiplier;
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

// ── Extraer tags de una página ──
function extractTags(string $html): array {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($doc);
    
    $tags = [];
    
    // Buscar <a class="name"> dentro del contenedor tag-listing-container
    $nodes = $xpath->query("//div[contains(@class, 'tag-listing-container')]//a[contains(@class, 'name')]");
    
    if ($nodes === false || $nodes->length === 0) {
        // Fallback: buscar cualquier <a class="name">
        $nodes = $xpath->query("//a[contains(@class, 'name')]");
    }
    
    foreach ($nodes as $node) {
        $tagName = trim($node->textContent);
        $qtyRaw = $node->getAttribute('data-qty');
        $qty = parseCount($qtyRaw);
        $href = $node->getAttribute('href');
        
        if ($tagName === '') continue;
        
        $tags[] = [
            'name'       => $tagName,
            'count_raw'  => $qtyRaw,
            'count'      => $qty,
            'url'        => $href,
        ];
    }
    
    return $tags;
}

// ── Cargar diccionario actual ──
function loadDictionary(): array {
    $mappings = TaxonomyData::getTagMappingsNormalized();
    $existingTags = TaxonomyData::getTagsNormalized();
    
    // Construir un mapa inverso: normalized → original mapping key
    $reverseMappings = [];
    foreach (TaxonomyData::getTagMappings() as $key => $value) {
        $reverseMappings[TaxonomyData::normalizeForSearch($key)] = [
            'source' => $key,
            'target' => $value,
        ];
    }
    
    $reverseExisting = [];
    foreach (TaxonomyData::getTags() as $tag) {
        $reverseExisting[TaxonomyData::normalizeForSearch($tag)] = $tag;
    }
    
    return [$mappings, $existingTags, $reverseMappings, $reverseExisting];
}

// ── Clasificar tag contra el diccionario ──
function classifyTag(string $tagName, array $reverseMappings, array $reverseExisting): array {
    $normalized = TaxonomyData::normalizeForSearch($tagName);
    
    // 1. Mapping directo
    if (isset($reverseMappings[$normalized])) {
        $m = $reverseMappings[$normalized];
        return [
            'status'    => 'MAPEADA',
            'target'    => $m['target'],
            'match_key' => $m['source'],
        ];
    }
    
    // 2. Sin modificador (parenthesis)
    $basePart = preg_replace('/\s*\([^)]+\)\s*$/', '', $normalized);
    $basePart = trim($basePart);
    if ($basePart !== '' && $basePart !== $normalized) {
        if (isset($reverseMappings[$basePart])) {
            $m = $reverseMappings[$basePart];
            return [
                'status'    => 'MAPEADA (sin modificador)',
                'target'    => $m['target'],
                'match_key' => $m['source'],
            ];
        }
    }
    
    // 3. Tag existente directo
    if (isset($reverseExisting[$normalized])) {
        return [
            'status'    => 'EXISTENTE',
            'target'    => $reverseExisting[$normalized],
            'match_key' => $reverseExisting[$normalized],
        ];
    }
    
    // 4. Tag existente sin modificador
    if ($basePart !== '' && $basePart !== $normalized) {
        if (isset($reverseExisting[$basePart])) {
            return [
                'status'    => 'EXISTENTE (sin modificador)',
                'target'    => $reverseExisting[$basePart],
                'match_key' => $reverseExisting[$basePart],
            ];
        }
    }
    
    return [
        'status'    => 'SIN MAPEO',
        'target'    => null,
        'match_key' => null,
    ];
}

// ── Obtener nivel por cantidad ──
function getLevel(int $count): string {
    if ($count >= 10000) return '🟣 NIVEL 1 (masiva)';
    if ($count >= 1000)  return '🔵 NIVEL 2 (popular)';
    if ($count >= 100)   return '🟢 NIVEL 3 (común)';
    if ($count >= 1)     return '🟡 NIVEL 4 (minoritaria)';
    return '⚫ IGNORADA (0 resultados)';
}

function getLevelSort(string $level): int {
    return match(true) {
        str_contains($level, 'NIVEL 1') => 1,
        str_contains($level, 'NIVEL 2') => 2,
        str_contains($level, 'NIVEL 3') => 3,
        str_contains($level, 'NIVEL 4') => 4,
        default => 5,
    };
}

// ═══════════════════════════════════════
//  MAIN
// ═══════════════════════════════════════

echo "=== RECOLECTOR DE ETIQUETAS DE 3HENTAI.NET ===\n\n";

// Cargar diccionario
[$mappings, $existingTags, $reverseMappings, $reverseExisting] = loadDictionary();
echo "Diccionario actual:\n";
echo "  Mappings: " . count(TaxonomyData::getTagMappings()) . "\n";
echo "  Tags existentes: " . count(TaxonomyData::getTags()) . "\n\n";

// Determinar páginas a escanear
$pagesToScan = $singlePage ? [$singlePage] : range(1, MAX_PAGES);
echo "Páginas a escanear: " . ($singlePage ? "{$singlePage}" : "1-" . MAX_PAGES) . "\n\n";

// Recolectar todas las etiquetas
$allTags = [];
$pageErrors = 0;

foreach ($pagesToScan as $page) {
    $html = fetchPage($page);
    if ($html === null) {
        $pageErrors++;
        continue;
    }
    
    $tags = extractTags($html);
    echo "    → {$page}: " . count($tags) . " etiquetas encontradas\n";
    
    foreach ($tags as $tag) {
        $key = $tag['name'];
        if (!isset($allTags[$key])) {
            $tag['pages'][] = $page;
            $allTags[$key] = $tag;
        } else {
            $allTags[$key]['pages'][] = $page;
        }
    }
    
    // Pequeña pausa para no saturar
    if (!$singlePage) {
        usleep(500000); // 0.5 segundos
    }
}

echo "\n";
echo "═══ RESULTADOS CRUDOS ═══\n";
echo "Total etiquetas únicas recolectadas: " . count($allTags) . "\n";
echo "Páginas con error: {$pageErrors}\n\n";

// Clasificar cada tag contra el diccionario
$classified = [];
foreach ($allTags as $name => $info) {
    $classification = classifyTag($name, $reverseMappings, $reverseExisting);
    $level = getLevel($info['count']);
    
    $classified[] = [
        'name'           => $name,
        'count'          => $info['count'],
        'count_raw'      => $info['count_raw'],
        'level'          => $level,
        'level_sort'     => getLevelSort($level),
        'status'         => $classification['status'],
        'target'         => $classification['target'],
        'match_key'      => $classification['match_key'],
        'pages'          => $info['pages'],
    ];
}

// Ordenar: primero por nivel (masivas primero), luego por cantidad descendente
usort($classified, function ($a, $b) {
    if ($a['level_sort'] !== $b['level_sort']) {
        return $a['level_sort'] - $b['level_sort'];
    }
    return $b['count'] - $a['count'];
});

// ── Reporte ──
echo "═══ REPORTE COMPLETO ═══\n\n";

// Conteos
$counts = [
    'MAPEADA'                 => 0,
    'MAPEADA (sin modificador)' => 0,
    'EXISTENTE'               => 0,
    'EXISTENTE (sin modificador)' => 0,
    'SIN MAPEO'               => 0,
];
$levelCounts = [];

foreach ($classified as $c) {
    $counts[$c['status']]++;
    $lvlKey = $c['level_sort'];
    if (!isset($levelCounts[$lvlKey])) {
        $levelCounts[$lvlKey] = ['total' => 0, 'unmapped' => 0, 'label' => $c['level']];
    }
    $levelCounts[$lvlKey]['total']++;
    if ($c['status'] === 'SIN MAPEO') {
        $levelCounts[$lvlKey]['unmapped']++;
    }
}

echo "RESUMEN POR ESTADO:\n";
foreach ($counts as $status => $cnt) {
    $pct = round($cnt / count($classified) * 100, 1);
    echo "  {$status}: {$cnt} ({$pct}%)\n";
}
echo "\n";

echo "RESUMEN POR NIVEL:\n";
ksort($levelCounts);
foreach ($levelCounts as $lvl) {
    echo "  {$lvl['label']}: {$lvl['total']} total, {$lvl['unmapped']} sin mapeo\n";
}
echo "\n";

// Etiquetas SIN MAPEO, ordenadas por popularidad
$unmapped = array_filter($classified, fn($c) => $c['status'] === 'SIN MAPEO');

echo "═══ ETIQUETAS SIN MAPEO (ordenadas por popularidad) ═══\n\n";
if (empty($unmapped)) {
    echo "  🎉 ¡Todas las etiquetas están mapeadas!\n";
} else {
    $currentLevel = '';
    foreach ($unmapped as $c) {
        $levelKey = $c['level'];
        if ($levelKey !== $currentLevel) {
            echo "\n--- {$levelKey} ---\n";
            $currentLevel = $levelKey;
        }
        echo sprintf(
            "  [%8s] %s\n",
            $c['count_raw'],
            $c['name']
        );
    }
}

// Etiquetas MAPEADAS (primeras 30 como muestra)
$mapped = array_filter($classified, fn($c) => str_starts_with($c['status'], 'MAPEADA'));
echo "\n\n═══ MUESTRA DE ETIQUETAS MAPEADAS (primeras 30) ═══\n\n";
$shown = 0;
foreach ($mapped as $c) {
    if ($shown >= 30) break;
    echo sprintf(
        "  [%8s] %-45s → %s  (%s)\n",
        $c['count_raw'],
        $c['name'],
        $c['target'],
        $c['status']
    );
    $shown++;
}
if (count($mapped) > 30) {
    echo "  ... y " . (count($mapped) - 30) . " más\n";
}

// ── Guardar a archivo si se solicitó ──
if ($saveFile) {
    $export = [
        'generated_at' => date('Y-m-d H:i:s'),
        'total_tags'   => count($classified),
        'summary'      => $counts,
        'tags'         => $classified,
    ];
    file_put_contents($saveFile, json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "\n\n📁 Listado completo guardado en: {$saveFile}\n";
}

echo "\n=== FIN ===\n";
