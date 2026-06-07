<?php

namespace ScrapApp\Commands;

use ScrapApp\Infrastructure\HttpClient;
use ScrapApp\Repositories\TaxonomyRepository;
use ScrapApp\Includes\UniverseProcessor;

/**
 * CLI command to harvest series/universes from 3hentai.net.
 *
 * Usage:
 *   php harvest_series.php
 *   php harvest_series.php --save=series.json
 */
class HarvestSeriesCommand
{
    private const BASE_URL  = 'https://es.3hentai.net/series';
    private const MAX_PAGES = 18;

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

    public function execute(?string $saveFile = null): int
    {
        echo "=== RECOLECTOR DE SERIES/UNIVERSOS DE 3HENTAI.NET ===\n\n";

        // Load existing universes
        [$universes, , $reverseNormalized] = $this->loadExistingUniverses();
        $processor = new UniverseProcessor();

        echo "Universos existentes en WordPress: " . count($universes) . "\n\n";

        // Scan pages
        echo "Páginas a escanear: 1-" . self::MAX_PAGES . "\n\n";

        $allSeries = [];
        $pageErrors = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $url = $page === 1 ? self::BASE_URL : self::BASE_URL . '?page=' . $page;

            $html = $this->httpClient->fetchPage($url);
            if ($html === null) {
                echo "  ⚠️  Error página {$page}\n";
                $pageErrors++;
                continue;
            }

            echo "  📄 Página {$page} obtenida (" . strlen($html) . " bytes)\n";

            $series = $this->extractSeries($html);
            echo "    → {$page}: " . count($series) . " series encontradas\n";

            foreach ($series as $s) {
                $key = $s['display_name'] . '|' . $s['slug_name'];
                if (!isset($allSeries[$key])) {
                    $allSeries[$key] = $s;
                }
            }

            usleep(500000);
        }

        echo "\n";
        echo "═══ RESULTADOS CRUDOS ═══\n";
        echo "Total series/universos únicos recolectados: " . count($allSeries) . "\n";
        echo "Páginas con error: {$pageErrors}\n\n";

        // Classify each series
        $classified = [];
        foreach ($allSeries as $key => $info) {
            $classification = $this->classifySeries(
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

        // Sort: matches first, then by count descending
        usort($classified, function ($a, $b) {
            $order = ['COINCIDENCIA EXACTA' => 0, 'COINCIDENCIA FUZZY' => 1, 'SIN COINCIDENCIA' => 2];
            $aOrder = $order[$a['status']] ?? 3;
            $bOrder = $order[$b['status']] ?? 3;
            if ($aOrder !== $bOrder) return $aOrder - $bOrder;
            return $b['count'] - $a['count'];
        });

        // ── Report ──
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

        // Existing universes and their status
        echo "═══ UNIVERSOS EXISTENTES EN WORDPRESS ═══\n\n";
        foreach ($universes as $univ) {
            $found = false;
            foreach ($classified as $c) {
                if ($c['matched'] !== null && strcasecmp($c['matched'], $univ) === 0) {
                    echo sprintf(
                        "  ✅ %-40s ← \"%s\" (%s)\n",
                        $univ,
                        $c['display_name'],
                        $c['count_raw']
                    );
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                echo "  ⚠️  {$univ} — NO ENCONTRADO en 3hentai.net\n";
            }
        }

        // Unmatched series (new)
        $unmatched = array_filter($classified, fn($c) => $c['status'] === 'SIN COINCIDENCIA');
        usort($unmatched, fn($a, $b) => $b['count'] - $a['count']);

        echo "\n═══ SERIES SIN COINCIDENCIA (NUEVAS - ordenadas por popularidad) ═══\n\n";

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
                echo sprintf("  [%8s] %s\n", $c['count_raw'], $c['display_name']);
                $shown++;
            }
        }

        // Fuzzy matches (review)
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

        // Save to file if requested
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
        return 0;
    }

    // ── Private Helpers ──

    private function loadExistingUniverses(): array
    {
        $universes = $this->taxRepo->getUniverses();
        $normalized = $this->taxRepo->getUniversesNormalized();

        $reverse = [];
        foreach ($universes as $univ) {
            $key = $this->taxRepo->normalizeForSearch($univ);
            $reverse[$key] = $univ;
        }

        return [$universes, $normalized, $reverse];
    }

    private function extractSeries(string $html): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($doc);

        $series = [];

        $nodes = $xpath->query("//div[contains(@class, 'tag-listing-container')]//a[contains(@class, 'name')]");
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query("//a[contains(@class, 'name')]");
        }

        foreach ($nodes as $node) {
            $displayName = trim($node->textContent);
            $qtyRaw      = $node->getAttribute('data-qty');
            $qty         = $this->parseCount($qtyRaw);
            $href        = $node->getAttribute('href');
            $slugName    = $this->slugToName($href);

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

    private function slugToName(string $slug): string
    {
        $slug = preg_replace('/^.*\/series\//', '', $slug);
        $slug = preg_replace('/^.*\//', '', $slug);
        $name = str_replace('-', ' ', $slug);
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function classifySeries(string $displayName, string $slugName, array $reverseNormalized, UniverseProcessor $processor): array
    {
        $displayLower = mb_strtolower(trim($displayName), 'UTF-8');
        $slugLower = mb_strtolower(trim($slugName), 'UTF-8');

        $candidates = preg_split('/\s*\|\s*/', $displayLower);
        $candidates[] = $slugLower;
        $candidates = array_unique(array_filter(array_map('trim', $candidates)));

        $bestResult = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $searchKey = $this->taxRepo->normalizeForSearch($candidate);

            if (strlen($searchKey) < 3) continue;

            // 1. Exact normalized match
            if (isset($reverseNormalized[$searchKey])) {
                $matched = $reverseNormalized[$searchKey];
                $score = 100;
                if ($bestResult === null || $score > $bestScore) {
                    $bestResult = [
                        'status'      => 'COINCIDENCIA EXACTA',
                        'matched'     => $matched,
                        'matched_key' => $candidate,
                        'score'       => $score,
                    ];
                    $bestScore = $score;
                }
                continue;
            }

            // 2. Fuzzy matching via UniverseProcessor
            $processed = $processor->process($candidate);
            if ($processed !== null) {
                $procKey = $this->taxRepo->normalizeForSearch($processed);
                if (isset($reverseNormalized[$procKey])) {
                    $matched = $reverseNormalized[$procKey];

                    similar_text($searchKey, $procKey, $score);
                    $score = $score / 100.0;

                    if ($score < 1.0 && $score >= 0.60) {
                        if (strpos($procKey, $searchKey) !== false || strpos($searchKey, $procKey) !== false) {
                            $score = max($score, 0.85);
                        }
                    }

                    $minFuzzyScore = strlen($searchKey) < 5 ? 90.0 : 82.0;

                    if ($score * 100 >= $minFuzzyScore) {
                        if ($bestResult === null || $score * 100 > $bestScore) {
                            $bestResult = [
                                'status'      => $score >= 0.99 ? 'COINCIDENCIA EXACTA' : 'COINCIDENCIA FUZZY',
                                'matched'     => $matched,
                                'matched_key' => $candidate,
                                'score'       => round($score * 100, 1),
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
            'status'      => 'SIN COINCIDENCIA',
            'matched'     => null,
            'matched_key' => null,
            'score'       => 0,
        ];
    }
}
