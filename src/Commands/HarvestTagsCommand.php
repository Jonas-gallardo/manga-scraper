<?php

namespace ScrapApp\Commands;

use ScrapApp\Infrastructure\HttpClient;
use ScrapApp\Repositories\TaxonomyRepository;

/**
 * CLI command to harvest tags from 3hentai.net.
 *
 * Usage:
 *   php harvest_tags.php
 *   php harvest_tags.php --save=tags.json
 *   php harvest_tags.php --page=1
 */
class HarvestTagsCommand
{
    private const BASE_URL  = 'https://es.3hentai.net/tags';
    private const MAX_PAGES = 22;

    private HttpClient $httpClient;
    private TaxonomyRepository $taxRepo;

    public function __construct()
    {
        $this->httpClient = new HttpClient([
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'timeout'    => 30,
        ]);
        $this->taxRepo = new TaxonomyRepository();
    }

    public function execute(?string $saveFile = null, ?int $singlePage = null): int
    {
        echo "=== RECOLECTOR DE ETIQUETAS DE 3HENTAI.NET ===\n\n";

        // Load dictionary
        [$reverseMappings, $reverseExisting] = $this->loadDictionary();
        echo "Diccionario actual:\n";
        echo "  Mappings: " . count($this->taxRepo->getTagMappings()) . "\n";
        echo "  Tags existentes: " . count($this->taxRepo->getTags()) . "\n\n";

        // Determine pages to scan
        $pagesToScan = $singlePage ? [$singlePage] : range(1, self::MAX_PAGES);
        echo "Páginas a escanear: " . ($singlePage ? "{$singlePage}" : "1-" . self::MAX_PAGES) . "\n\n";

        // Collect all tags
        $allTags = [];
        $pageErrors = 0;

        foreach ($pagesToScan as $page) {
            $url = $page === 1 ? self::BASE_URL : self::BASE_URL . '?page=' . $page;

            $html = $this->httpClient->fetchPage($url);
            if ($html === null) {
                echo "  ⚠️  Error página {$page}\n";
                $pageErrors++;
                continue;
            }

            echo "  📄 Página {$page} obtenida (" . strlen($html) . " bytes)\n";

            $tags = $this->extractTags($html);
            echo "    → {$page}: " . count($tags) . " etiquetas encontradas\n";

            foreach ($tags as $tag) {
                $key = $tag['name'];
                if (!isset($allTags[$key])) {
                    $tag['pages'] = [$page];
                    $allTags[$key] = $tag;
                } else {
                    $allTags[$key]['pages'][] = $page;
                }
            }

            // Small delay to avoid flooding
            if (!$singlePage) {
                usleep(500000);
            }
        }

        echo "\n";
        echo "═══ RESULTADOS CRUDOS ═══\n";
        echo "Total etiquetas únicas recolectadas: " . count($allTags) . "\n";
        echo "Páginas con error: {$pageErrors}\n\n";

        // Classify each tag
        $classified = [];
        foreach ($allTags as $name => $info) {
            $classification = $this->classifyTag($name, $reverseMappings, $reverseExisting);
            $level = $this->getLevel($info['count']);

            $classified[] = [
                'name'       => $name,
                'count'      => $info['count'],
                'count_raw'  => $info['count_raw'],
                'level'      => $level,
                'level_sort' => $this->getLevelSort($level),
                'status'     => $classification['status'],
                'target'     => $classification['target'],
                'match_key'  => $classification['match_key'],
                'pages'      => $info['pages'],
            ];
        }

        // Sort: by level first, then by count descending
        usort($classified, function ($a, $b) {
            if ($a['level_sort'] !== $b['level_sort']) {
                return $a['level_sort'] - $b['level_sort'];
            }
            return $b['count'] - $a['count'];
        });

        // ── Report ──
        echo "═══ REPORTE COMPLETO ═══\n\n";

        $counts = [
            'MAPEADA'                    => 0,
            'MAPEADA (sin modificador)'  => 0,
            'EXISTENTE'                  => 0,
            'EXISTENTE (sin modificador)' => 0,
            'SIN MAPEO'                  => 0,
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

        // Unmapped tags
        $unmapped = array_filter($classified, fn($c) => $c['status'] === 'SIN MAPEO');
        echo "═══ ETIQUETAS SIN MAPEO (ordenadas por popularidad) ═══\n\n";
        if (empty($unmapped)) {
            echo "  🎉 ¡Todas las etiquetas están mapeadas!\n";
        } else {
            $currentLevel = '';
            foreach ($unmapped as $c) {
                if ($c['level'] !== $currentLevel) {
                    echo "\n--- {$c['level']} ---\n";
                    $currentLevel = $c['level'];
                }
                echo sprintf("  [%8s] %s\n", $c['count_raw'], $c['name']);
            }
        }

        // Mapped sample
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

        // Save to file if requested
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
        return 0;
    }

    // ── Private Helpers ──

    private function loadDictionary(): array
    {
        $mappings = $this->taxRepo->getTagMappingsNormalized();
        $existingTags = $this->taxRepo->getTagsNormalized();

        $reverseMappings = [];
        foreach ($this->taxRepo->getTagMappings() as $key => $value) {
            $reverseMappings[$this->taxRepo->normalizeForSearch($key)] = [
                'source' => $key,
                'target' => $value,
            ];
        }

        $reverseExisting = [];
        foreach ($this->taxRepo->getTags() as $tag) {
            $reverseExisting[$this->taxRepo->normalizeForSearch($tag)] = $tag;
        }

        return [$reverseMappings, $reverseExisting];
    }

    private function extractTags(string $html): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($doc);

        $tags = [];

        // Look for <a class="name"> inside tag-listing-container
        $nodes = $xpath->query("//div[contains(@class, 'tag-listing-container')]//a[contains(@class, 'name')]");
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query("//a[contains(@class, 'name')]");
        }

        foreach ($nodes as $node) {
            $tagName = trim($node->textContent);
            $qtyRaw  = $node->getAttribute('data-qty');
            $qty     = $this->parseCount($qtyRaw);
            $href    = $node->getAttribute('href');

            if ($tagName === '') continue;

            $tags[] = [
                'name'      => $tagName,
                'count_raw' => $qtyRaw,
                'count'     => $qty,
                'url'       => $href,
            ];
        }

        return $tags;
    }

    private function parseCount(string $qty): int
    {
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

    private function classifyTag(string $tagName, array $reverseMappings, array $reverseExisting): array
    {
        $normalized = $this->taxRepo->normalizeForSearch($tagName);

        // 1. Direct mapping
        if (isset($reverseMappings[$normalized])) {
            $m = $reverseMappings[$normalized];
            return ['status' => 'MAPEADA', 'target' => $m['target'], 'match_key' => $m['source']];
        }

        // 2. Without modifier (parenthesis)
        $basePart = preg_replace('/\s*\([^)]+\)\s*$/', '', $normalized);
        $basePart = trim($basePart);
        if ($basePart !== '' && $basePart !== $normalized && isset($reverseMappings[$basePart])) {
            $m = $reverseMappings[$basePart];
            return ['status' => 'MAPEADA (sin modificador)', 'target' => $m['target'], 'match_key' => $m['source']];
        }

        // 3. Existing tag direct
        if (isset($reverseExisting[$normalized])) {
            return ['status' => 'EXISTENTE', 'target' => $reverseExisting[$normalized], 'match_key' => $reverseExisting[$normalized]];
        }

        // 4. Existing tag without modifier
        if ($basePart !== '' && $basePart !== $normalized && isset($reverseExisting[$basePart])) {
            return ['status' => 'EXISTENTE (sin modificador)', 'target' => $reverseExisting[$basePart], 'match_key' => $reverseExisting[$basePart]];
        }

        return ['status' => 'SIN MAPEO', 'target' => null, 'match_key' => null];
    }

    private function getLevel(int $count): string
    {
        if ($count >= 10000) return '🟣 NIVEL 1 (masiva)';
        if ($count >= 1000)  return '🔵 NIVEL 2 (popular)';
        if ($count >= 100)   return '🟢 NIVEL 3 (común)';
        if ($count >= 1)     return '🟡 NIVEL 4 (minoritaria)';
        return '⚫ IGNORADA (0 resultados)';
    }

    private function getLevelSort(string $level): int
    {
        return match (true) {
            str_contains($level, 'NIVEL 1') => 1,
            str_contains($level, 'NIVEL 2') => 2,
            str_contains($level, 'NIVEL 3') => 3,
            str_contains($level, 'NIVEL 4') => 4,
            default => 5,
        };
    }
}
