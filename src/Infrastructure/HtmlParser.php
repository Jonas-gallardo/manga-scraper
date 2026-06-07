<?php

declare(strict_types=1);

namespace ScrapApp\Infrastructure;

/**
 * HtmlParser.php
 *
 * Utilidades de parseo de HTML usando DOMDocument + DOMXPath.
 * Encapsula todas las funciones de extracción de metadatos de scraper.php.
 *
 * @package ScrapApp
 * @subpackage Infrastructure
 */
class HtmlParser
{
    /**
     * Parsea HTML con DOMDocument y devuelve un DOMXPath.
     *
     * @param string $html HTML a parsear
     * @return \DOMXPath|null Objeto XPath o null si falla
     */
    public static function createXPath(string $html): ?\DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        return new \DOMXPath($dom);
    }

    /**
     * Extrae el contenido de una meta tag por property o name.
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $property Valor de property o name
     * @return string|null Contenido de la meta tag o null
     */
    public static function extractMeta(\DOMXPath $xpath, string $property): ?string
    {
        // Por property
        $nodes = $xpath->query("//meta[@property='$property']");
        if ($nodes && $nodes->length > 0) {
            $content = $nodes->item(0)->getAttribute('content');
            if ($content) {
                return trim($content);
            }
        }
        // Por name
        $nodes = $xpath->query("//meta[@name='$property']");
        if ($nodes && $nodes->length > 0) {
            $content = $nodes->item(0)->getAttribute('content');
            if ($content) {
                return trim($content);
            }
        }
        return null;
    }

    /**
     * Extrae el texto de un elemento por selector XPath.
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $query Consulta XPath
     * @return string|null Texto del elemento o null
     */
    public static function extractText(\DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return null;
    }

    /**
     * Extrae valores de una sección específica dentro de div.tag-container.field-name.
     * Identifica la sección por su label de texto (ej. "Series:", "Personajes:", etc.).
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $sectionLabel Label exacto de la sección (ej. "Series:", "Etiquetas:")
     * @return array<string> Valores encontrados dentro de <a class="name">
     */
    public static function extractSectionValues(\DOMXPath $xpath, string $sectionLabel): array
    {
        $result = [];
        $containers = $xpath->query("//div[contains(@class, 'tag-container') and contains(@class, 'field-name')]");
        foreach ($containers as $container) {
            $firstText = trim($container->textContent);
            if (strpos($firstText, $sectionLabel) === 0) {
                $links = $xpath->query(".//a[contains(@class, 'name')]", $container);
                foreach ($links as $link) {
                    $val = trim($link->textContent);
                    if ($val !== '') {
                        $result[] = $val;
                    }
                }
                break;
            }
        }
        return $result;
    }

    /**
     * Extrae el título del cómic usando DOMXPath.
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $html HTML completo (para regex fallback)
     * @return string Título extraído o "Título desconocido"
     */
    public static function extractTitle(\DOMXPath $xpath, string $html): string
    {
        $og = self::extractMeta($xpath, 'og:title');
        if ($og) {
            return $og;
        }

        $h1 = self::extractText($xpath, "//h1[contains(@class, 'title')]");
        if ($h1) {
            return $h1;
        }

        $title = self::extractText($xpath, '//title');
        if ($title) {
            $title = preg_replace('#\s*[|-]\s*[^-|]+$#', '', $title);
            return trim($title);
        }

        $h1 = self::extractText($xpath, '//h1');
        if ($h1) {
            return $h1;
        }

        return 'Título desconocido';
    }

    /**
     * Extrae el número TOTAL de páginas del cómic.
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $html HTML completo (para regex fallback)
     * @return int Número de páginas (mínimo 1)
     */
    public static function extractTotalPages(\DOMXPath $xpath, string $html): int
    {
        $default = 20;

        // 1 — Texto "Pages: N" o "Páginas: N"
        if (preg_match('#(?:Pages?|Páginas?)\s*:?\s*(\d+)#i', $html, $m)) {
            return max(1, (int) $m[1]);
        }
        // 2 — "of N" / "de N" en paginación
        if (preg_match('#(?:page|pág\.?)\s*\d+\s*(?:of|de|/)\s*(\d+)#i', $html, $m)) {
            return max(1, (int) $m[1]);
        }
        // 3 — data-total-pages
        $nodes = $xpath->query("//*[@data-total-pages or @data-total]");
        if ($nodes && $nodes->length > 0) {
            $val = $nodes->item(0)->getAttribute('data-total-pages') ?: $nodes->item(0)->getAttribute('data-total');
            if ($val && is_numeric($val)) {
                return max(1, (int) $val);
            }
        }
        // 4 — Extraer números de SITE_VIEW_PATH/ID/N
        $viewPath = defined('SITE_VIEW_PATH') ? SITE_VIEW_PATH : '/view';
        $escapedView = preg_quote($viewPath, '#');
        if (preg_match_all('#' . $escapedView . '/\d+/(\d+)#', $html, $m)) {
            $nums = array_map('intval', $m[1]);
            if (!empty($nums)) {
                return max(1, max($nums));
            }
        }
        // 5 — Clase con total-pages
        $txt = self::extractText($xpath, "//*[contains(@class, 'total-pages') or contains(@class, 'page-count') or contains(@class, 'num-pages')]");
        if ($txt && is_numeric($txt)) {
            return max(1, (int) $txt);
        }

        return $default;
    }

    /**
     * Extrae autor del cómic.
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $html HTML completo
     * @return string|null Nombre del autor o null
     */
    public static function extractAuthor(\DOMXPath $xpath, string $html): ?string
    {
        $author = self::extractMeta($xpath, 'author');
        if ($author) {
            return $author;
        }
        $author = self::extractMeta($xpath, 'og:author');
        if ($author) {
            return $author;
        }
        $txt = self::extractText($xpath, "//*[contains(@class, 'author') or contains(@class, 'writer')]//a | //*[contains(@class, 'author') or contains(@class, 'writer')]");
        if ($txt) {
            return $txt;
        }
        return null;
    }

    /**
     * Extrae tags/etiquetas del cómic.
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $html HTML completo
     * @return string|null Tags separados por coma o null
     */
    public static function extractTags(\DOMXPath $xpath, string $html): ?string
    {
        $kw = self::extractMeta($xpath, 'keywords');
        if ($kw) {
            return $kw;
        }
        $tags = self::extractSectionValues($xpath, 'Etiquetas:');
        if (!empty($tags)) {
            return implode(', ', array_unique($tags));
        }
        return null;
    }

    /**
     * Extrae la serie/universo del cómic desde la sección "Series:".
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @return string|null Nombre(s) de serie separados por coma, o null
     */
    public static function extractSeries(\DOMXPath $xpath): ?string
    {
        $values = self::extractSectionValues($xpath, 'Series:');
        return !empty($values) ? implode(', ', array_unique($values)) : null;
    }

    /**
     * Extrae los personajes del cómic desde la sección "Personajes:".
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @return string|null Nombres de personajes separados por coma, o null
     */
    public static function extractCharacters(\DOMXPath $xpath): ?string
    {
        $values = self::extractSectionValues($xpath, 'Personajes:');
        return !empty($values) ? implode(', ', array_unique($values)) : null;
    }

    /**
     * Extrae los artistas desde la sección "Artistas:".
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @return string|null Nombres de artistas separados por coma, o null
     */
    public static function extractArtists(\DOMXPath $xpath): ?string
    {
        $values = self::extractSectionValues($xpath, 'Artistas:');
        return !empty($values) ? implode(', ', array_unique($values)) : null;
    }

    /**
     * Extrae la categoría del cómic desde la sección "Categorías:".
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @return string|null Nombre(s) de categoría separados por coma, o null
     */
    public static function extractCategories(\DOMXPath $xpath): ?string
    {
        $values = self::extractSectionValues($xpath, 'Categorías:');
        return !empty($values) ? implode(', ', array_unique($values)) : null;
    }

    /**
     * Extrae sinopsis/descripción del cómic.
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $html HTML completo
     * @return string|null Sinopsis o null
     */
    public static function extractSynopsis(\DOMXPath $xpath, string $html): ?string
    {
        $desc = self::extractMeta($xpath, 'description');
        if ($desc) {
            return $desc;
        }
        $desc = self::extractMeta($xpath, 'og:description');
        if ($desc) {
            return $desc;
        }
        $txt = self::extractText($xpath, "//*[contains(@class, 'description') or contains(@class, 'summary') or contains(@class, 'sinopsis')]");
        if ($txt) {
            return $txt;
        }
        return null;
    }

    /**
     * Extrae idioma del cómic.
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $html HTML completo
     * @return string|null Idioma o null
     */
    public static function extractLanguage(\DOMXPath $xpath, string $html): ?string
    {
        $nodes = $xpath->query('//html/@lang');
        if ($nodes && $nodes->length > 0) {
            $lang = trim($nodes->item(0)->value);
            if ($lang !== '') {
                return $lang;
            }
        }
        $lang = self::extractMeta($xpath, 'language');
        if ($lang) {
            return $lang;
        }
        $idiomas = self::extractSectionValues($xpath, 'Idiomas:');
        if (!empty($idiomas)) {
            $realLang = array_filter($idiomas, function ($v) {
                return mb_strtolower(trim($v), 'UTF-8') !== 'translated';
            });
            if (!empty($realLang)) {
                return implode(', ', array_unique($realLang));
            }
        }
        return null;
    }

    /**
     * Extrae rating/calificación del cómic.
     *
     * @param \DOMXPath $xpath Objeto XPath
     * @param string $html HTML completo
     * @return float|null Rating o null
     */
    public static function extractRating(\DOMXPath $xpath, string $html): ?float
    {
        $rating = self::extractMeta($xpath, 'rating');
        if ($rating && is_numeric($rating)) {
            return (float) $rating;
        }
        $txt = self::extractText($xpath, "//*[contains(@class, 'rating') or contains(@class, 'score')]");
        if ($txt) {
            if (preg_match('#(\d+(?:\.\d+)?)\s*/\s*\d+#', $txt, $m)) {
                return (float) $m[1];
            }
            if (is_numeric(trim($txt))) {
                return (float) trim($txt);
            }
        }
        return null;
    }

    /**
     * Busca enlaces de cómics en el HTML usando DOMXPath.
     * Usa el path configurado (ej: /d/ID o /view/ID).
     *
     * @param string $html HTML de la página del listado
     * @return array<string> URLs de cómics encontradas
     */
    public static function extractComicLinks(string $html): array
    {
        $enlaces = [];
        $xpath = self::createXPath($html);
        if (!$xpath) {
            return $enlaces;
        }

        $viewPath = defined('SITE_VIEW_PATH') ? SITE_VIEW_PATH : '/view';
        $viewBase = defined('SITE_VIEW') ? SITE_VIEW : '';
        $siteBase = defined('SITE_BASE') ? SITE_BASE : '';
        $escaped = preg_quote($viewPath, '#');

        // 1 — Buscar enlaces que contengan el path configurado + número
        $nodes = $xpath->query("//a[contains(@href, '$viewPath/')]");
        if ($nodes) {
            foreach ($nodes as $node) {
                $href = $node->getAttribute('href');
                if (preg_match('#' . $escaped . '/(\d+)#', $href, $m)) {
                    $enlaces[] = $viewBase . '/' . $m[1];
                }
            }
        }

        // 2 — Enlaces con solo ID numérico (ej: href="/676046")
        if (empty($enlaces)) {
            $nodes = $xpath->query("//a[contains(@href, '/')]");
            if ($nodes) {
                foreach ($nodes as $node) {
                    $href = $node->getAttribute('href');
                    if (preg_match('#^/(\d+)/?(?:\?.*)?$#', $href, $m)) {
                        $enlaces[] = $siteBase . '/' . $m[1];
                    }
                }
            }
        }

        // 3 — Regex fallback: path configurado
        if (empty($enlaces)) {
            if (preg_match_all('#' . $escaped . '/(\d+)#', $html, $m)) {
                $ids = array_unique($m[1]);
                foreach ($ids as $id) {
                    $enlaces[] = $viewBase . '/' . $id;
                }
            }
        }

        // 4 — Regex fallback: cualquier número al final de href
        if (empty($enlaces)) {
            if (preg_match_all('#/(\d+)(?:/|$)#', $html, $m)) {
                $ids = array_unique($m[1]);
                foreach ($ids as $id) {
                    $enlaces[] = $siteBase . '/' . $id;
                }
            }
        }

        return array_values(array_unique($enlaces));
    }
}
