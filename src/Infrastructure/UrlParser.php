<?php

declare(strict_types=1);

namespace ScrapApp\Infrastructure;

/**
 * UrlParser.php
 *
 * Utilidades para parseo y validación de URLs específicas del sitio de origen.
 * Encapsula extraer_id(), extraer_universo() y validar_url() de scraper.php.
 *
 * @package ScrapApp
 * @subpackage Infrastructure
 */
class UrlParser
{
    /**
     * Extrae el ID numérico de una URL usando el path configurado (ej: /d/ID o /view/ID).
     * También soporta URLs con solo el ID numérico al final (ej: /676046).
     *
     * @param string $url URL del cómic
     * @return int|null ID numérico extraído o null
     */
    public static function extractId(string $url): ?int
    {
        $siteViewPath = defined('SITE_VIEW_PATH') ? SITE_VIEW_PATH : '/view';
        $escapedPath = preg_quote($siteViewPath, '#');

        // 1 — Intentar con el path configurado (ej: /d/ID o /view/ID)
        if (preg_match('#' . $escapedPath . '/(\d+)#', $url, $m)) {
            return (int) $m[1];
        }
        // 2 — URL completa tipo https://dominio/ID (sin path)
        if (preg_match('#https?://[^/]+/(\d+)(?:/|$)#', $url, $m)) {
            return (int) $m[1];
        }
        // 3 — Fallback: cualquier ruta que termine en /ID o /ID/
        if (preg_match('#/(\d+)/?(?:\?.*)?$#', $url, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Extrae el nombre del universo desde /parody/... o /search?q=...
     *
     * @param string $url URL del batch/universo
     * @return string|null Nombre del universo o null
     */
    public static function extractUniverse(string $url): ?string
    {
        $siteBatchPath = defined('SITE_BATCH_PATH') ? SITE_BATCH_PATH : '/parody';
        $escapedBatch = preg_quote($siteBatchPath, '~');

        // Intentar con el path batch configurado (ej: /parody/NOMBRE)
        if (preg_match('~' . $escapedBatch . '/([^/?#]+)~', $url, $m)) {
            return urldecode(str_replace(['-', '_'], ' ', $m[1]));
        }
        // /search?q=...
        if (preg_match('~' . $escapedBatch . '\?q=([^&#]+)~', $url, $m)) {
            return urldecode(str_replace(['-', '_', '+'], ' ', $m[1]));
        }
        // Fallback genérico para search query
        if (preg_match('~[?&]q=([^&#]+)~', $url, $m)) {
            return urldecode(str_replace(['-', '_', '+'], ' ', $m[1]));
        }
        return null;
    }

    /**
     * Valida que la URL tenga el formato esperado según el path configurado.
     *
     * @param string $url URL a validar
     * @param string $tipo Tipo: 'single' o 'batch'
     * @return bool True si la URL es válida para el tipo
     */
    public static function validate(string $url, string $tipo): bool
    {
        $siteViewPath = defined('SITE_VIEW_PATH') ? SITE_VIEW_PATH : '/view';
        $siteBatchPath = defined('SITE_BATCH_PATH') ? SITE_BATCH_PATH : '/parody';

        $escapedView  = preg_quote($siteViewPath, '#');
        $escapedBatch = preg_quote($siteBatchPath, '#');

        if ($tipo === 'single') {
            return (bool) preg_match('#^https?://[^/]+' . $escapedView . '/\d+#', $url);
        }
        if ($tipo === 'batch') {
            return (bool) preg_match('#^https?://[^/]+' . $escapedBatch . '#', $url);
        }
        return false;
    }
}
