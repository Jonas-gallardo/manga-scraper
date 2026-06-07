<?php
/**
 * TaxonomyData.php
 *
 * === DEPRECATED — Se mantiene solo por compatibilidad ===
 *
 * Ahora los datos se cargan desde archivos JSON mediante TaxonomyRepository.
 * Esta clase sigue funcionando pero internamente delega en el repositorio.
 *
 * Para nuevo código, usar:
 *   $repo = new \ScrapApp\Repositories\TaxonomyRepository();
 *   $repo->getTags();
 *   $repo->getTagMappings();
 *   $repo->getUniverses();
 *   $repo->normalizeForSearch($text);
 *
 * @see \ScrapApp\Repositories\TaxonomyRepository
 * @package ScrapApp
 * @subpackage Taxonomy
 * @deprecated Usar TaxonomyRepository en su lugar
 */

require_once __DIR__ . '/../autoload.php';

class TaxonomyData
{
    private static ?\ScrapApp\Repositories\TaxonomyRepository $repo = null;

    /**
     * Obtiene (o crea) la instancia del repositorio.
     */
    private static function getRepo(): \ScrapApp\Repositories\TaxonomyRepository
    {
        if (self::$repo === null) {
            self::$repo = new \ScrapApp\Repositories\TaxonomyRepository();
        }
        return self::$repo;
    }

    /**
     * Retorna la lista completa de etiquetas existentes.
     *
     * @return array<string>
     */
    public static function getTags(): array
    {
        return self::getRepo()->getTags();
    }

    /**
     * Retorna el mapa de equivalencias: tag_origen → tag_destino.
     *
     * @return array<string, string>
     */
    public static function getTagMappings(): array
    {
        return self::getRepo()->getTagMappings();
    }

    /**
     * Retorna el mapa de equivalencias con claves normalizadas.
     *
     * @return array<string, string>
     */
    public static function getTagMappingsNormalized(): array
    {
        return self::getRepo()->getTagMappingsNormalized();
    }

    /**
     * Retorna la lista completa de universos existentes.
     *
     * @return array<string>
     */
    public static function getUniverses(): array
    {
        return self::getRepo()->getUniverses();
    }

    /**
     * Retorna las etiquetas normalizadas para búsqueda.
     *
     * @return array<string, string>
     */
    public static function getTagsNormalized(): array
    {
        return self::getRepo()->getTagsNormalized();
    }

    /**
     * Retorna los universos normalizados para búsqueda.
     *
     * @return array<string, string>
     */
    public static function getUniversesNormalized(): array
    {
        return self::getRepo()->getUniversesNormalized();
    }

    /**
     * Normaliza un string para búsqueda.
     *
     * @param string $text
     * @return string
     */
    public static function normalizeForSearch(string $text): string
    {
        return self::getRepo()->normalizeForSearch($text);
    }
}
