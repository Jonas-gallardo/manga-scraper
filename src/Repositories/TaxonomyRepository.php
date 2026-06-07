<?php

declare(strict_types=1);

namespace ScrapApp\Repositories;

use ScrapApp\Infrastructure\JsonLoader;

/**
 * TaxonomyRepository.php
 *
 * Repositorio de datos de taxonomía.
 * Reemplaza la clase estática TaxonomyData cargando los datos desde
 * archivos JSON externos (data/tags.json, data/tag_mappings.json, data/universes.json).
 *
 * Beneficios:
 *   - Los datos de taxonomía NO están hardcodeados en el código fuente
 *   - Se pueden modificar sin cambiar código PHP
 *   - La clase es testeable (se puede mockear el JsonLoader)
 *   - Carga bajo demanda con caché en memoria
 *
 * @package ScrapApp
 * @subpackage Repositories
 */
class TaxonomyRepository
{
    private JsonLoader $loader;

    /** @var array<string, string>|null Cache de etiquetas normalizadas */
    private ?array $tagsNormalizedCache = null;

    /** @var array<string, string>|null Cache de mappings normalizados */
    private ?array $mappingsNormalizedCache = null;

    /** @var array<string, string>|null Cache de universos normalizados */
    private ?array $universesNormalizedCache = null;

    public function __construct(?JsonLoader $loader = null)
    {
        $this->loader = $loader ?? new JsonLoader();
    }

    /**
     * Retorna la lista completa de etiquetas existentes.
     *
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->loader->loadArray('tags.json');
    }

    /**
     * Retorna el mapa de equivalencias: tag_origen → tag_destino.
     *
     * @return array<string, string>
     */
    public function getTagMappings(): array
    {
        return $this->loader->loadArray('tag_mappings.json');
    }

    /**
     * Retorna el mapa de equivalencias con claves normalizadas para búsqueda rápida.
     *
     * @return array<string, string>  normalized_source_key → target_tag (lowercase)
     */
    public function getTagMappingsNormalized(): array
    {
        if ($this->mappingsNormalizedCache !== null) {
            return $this->mappingsNormalizedCache;
        }

        $normalized = [];
        foreach ($this->getTagMappings() as $source => $target) {
            $key = $this->normalizeForSearch($source);
            $normalized[$key] = mb_strtolower($target, 'UTF-8');
        }

        $this->mappingsNormalizedCache = $normalized;
        return $normalized;
    }

    /**
     * Retorna la lista completa de universos existentes.
     *
     * @return array<string>
     */
    public function getUniverses(): array
    {
        return $this->loader->loadArray('universes.json');
    }

    /**
     * Retorna las etiquetas en formato normalizado para búsqueda.
     *
     * @return array<string, string>  Clave normalizada → valor original
     */
    public function getTagsNormalized(): array
    {
        if ($this->tagsNormalizedCache !== null) {
            return $this->tagsNormalizedCache;
        }

        $normalized = [];
        foreach ($this->getTags() as $tag) {
            $key = $this->normalizeForSearch($tag);
            $normalized[$key] = $tag;
        }

        $this->tagsNormalizedCache = $normalized;
        return $normalized;
    }

    /**
     * Retorna los universos en formato normalizado para búsqueda.
     *
     * @return array<string, string>  Clave normalizada → valor original
     */
    public function getUniversesNormalized(): array
    {
        if ($this->universesNormalizedCache !== null) {
            return $this->universesNormalizedCache;
        }

        $normalized = [];
        foreach ($this->getUniverses() as $universe) {
            $key = $this->normalizeForSearch($universe);
            $normalized[$key] = $universe;
        }

        $this->universesNormalizedCache = $normalized;
        return $normalized;
    }

    /**
     * Carga mappings personalizados desde custom_mappings.json y los combina
     * con los mappings por defecto.
     *
     * @return array<string, string>  normalized_source_key → target_tag (lowercase)
     */
    public function getCustomMappingsNormalized(): array
    {
        $custom = $this->loader->loadArray('custom_mappings.json');
        $normalized = [];

        if (isset($custom['tags']) && is_array($custom['tags'])) {
            foreach ($custom['tags'] as $original => $destino) {
                $key = $this->normalizeForSearch((string) $original);
                $normalized[$key] = mb_strtolower((string) $destino, 'UTF-8');
            }
        }

        return $normalized;
    }

    /**
     * Carga universos personalizados y los combina con los por defecto.
     *
     * @return array<string, string>  normalized_key → original_value
     */
    public function getCustomUniversesNormalized(): array
    {
        $custom = $this->loader->loadArray('custom_mappings.json');
        $normalized = [];

        if (isset($custom['universes']) && is_array($custom['universes'])) {
            foreach ($custom['universes'] as $univName) {
                $key = $this->normalizeForSearch((string) $univName);
                $normalized[$key] = (string) $univName;
            }
        }

        return $normalized;
    }

    /**
     * Normaliza un string para búsqueda: minúsculas, sin puntuación redundante,
     * espacios simples.
     *
     * @param string $text
     * @return string
     */
    public function normalizeForSearch(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[–—_-]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Refresca todas las cachés (útil tras modificar un archivo JSON).
     */
    public function clearCache(): void
    {
        $this->tagsNormalizedCache = null;
        $this->mappingsNormalizedCache = null;
        $this->universesNormalizedCache = null;
        JsonLoader::clearCache();
    }
}
