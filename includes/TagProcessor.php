<?php
/**
 * TagProcessor.php
 *
 * Procesa etiquetas (tags) extraídas de la fuente de scraping.
 * Aplica las reglas estrictas de limpieza y normalización definidas
 * para el módulo de taxonomías de Gluglux.
 *
 * Reglas:
 *   1. Minúsculas: Todo el texto estrictamente a minúsculas.
 *   2. Espaciado: Conceptos compuestos separados con espacios, NUNCA guiones.
 *      Ej: "ai-generated" → "ai generated"
 *   3. Modificadores: Respetar paréntesis al final (ej. "oral (femenino)").
 *   4. Caracteres permitidos: Números al inicio (ej. "3d") y puntos para siglas.
 *   5. Lógica: Extraer, formatear y cruzar con lista de etiquetas existentes
 *      para evitar duplicados y normalizar.
 *
 * @package ScrapApp
 * @subpackage Taxonomy
 */

require_once __DIR__ . '/TaxonomyData.php';

class TagProcessor
{
    /**
     * Lista de etiquetas existentes en WordPress (forma normalizada).
     * Cache para búsquedas rápidas.
     *
     * @var array<string, string>  normalized_key => original_value
     */
    private array $existingTags;

    /**
     * MAPA DE EQUIVALENCIAS: tags del scraping → tags de WordPress.
     * Cache del diccionario de traducción tag_origen → tag_destino.
     *
     * @var array<string, string>  normalized_source_key => target_tag (lowercase)
     */
    private array $tagMappings;

    /**
     * Ruta del archivo de log para tags no mapeados.
     *
     * @var string
     */
    private string $unmappedLogFile;

    /**
     * Umbral de similitud para fuzzy matching (0.0 - 1.0).
     *
     * @var float
     */
    private float $fuzzyThreshold;

    /**
     * @param float $fuzzyThreshold Umbral de similitud (default 0.75) para emparejar tags
     */
    public function __construct(float $fuzzyThreshold = 0.82)
    {
        $this->existingTags = TaxonomyData::getTagsNormalized();
        $this->tagMappings = TaxonomyData::getTagMappingsNormalized();
        $this->fuzzyThreshold = $fuzzyThreshold;

        // ── Cargar mappings personalizados desde data/custom_mappings.json ──
        $customFile = __DIR__ . '/../data/custom_mappings.json';
        if (file_exists($customFile)) {
            $customData = json_decode(file_get_contents($customFile), true);
            if (is_array($customData) && isset($customData['tags'])) {
                foreach ($customData['tags'] as $original => $destino) {
                    $key = TaxonomyData::normalizeForSearch($original);
                    $this->tagMappings[$key] = mb_strtolower($destino, 'UTF-8');
                }
            }
        }
        $this->unmappedLogFile = __DIR__ . '/../logs/unmapped_tags.log';
    }

    /**
     * Procesa un string de etiquetas (separadas por coma, espacio o línea)
     * y retorna un array limpio, normalizado y validado contra las existentes.
     *
     * @param string|null $rawTags Texto crudo de etiquetas extraído de la fuente
     * @return array<string> Array de etiquetas normalizadas, vacío si no hay
     */
    public function process(?string $rawTags): array
    {
        if ($rawTags === null || trim($rawTags) === '') {
            return [];
        }

        // 1. Dividir en etiquetas individuales
        $rawItems = $this->splitTags($rawTags);

        // 2. Limpiar y normalizar cada una
        $processed = [];
        foreach ($rawItems as $rawTag) {
            $cleaned = $this->cleanTag($rawTag);
            if ($cleaned !== null) {
                $processed[] = $cleaned;
            }
        }

        // 3. Eliminar duplicados internos
        $processed = array_unique($processed);

        // 4. Cruzar con etiquetas existentes (normalizar contra la referencia)
        //    SOLO se conservan las que tienen equivalencia en el diccionario o
        //    en la lista de etiquetas existentes. Las que no tienen equivalencia
        //    se IGNORAN (no se incluyen en el resultado final).
        $finalTags = [];
        foreach ($processed as $tag) {
            $matched = $this->matchExisting($tag);
            if ($matched !== null) {
                // Usar la forma canónica (existente en WordPress)
                $finalTags[] = $matched;
            } else {
                // No encontrada en referencia → loguear como no mapeada e IGNORAR
                $this->logUnmappedTag($tag);
            }
        }

        return array_values(array_unique($finalTags));
    }

    /**
     * Divide el texto crudo en etiquetas individuales.
     * Soporta separadores: coma, punto y coma, barra vertical, salto de línea.
     *
     * @param string $rawText
     * @return array<string>
     */
    private function splitTags(string $rawText): array
    {
        // Normalizar separadores: saltos de línea y tabs a coma
        $text = preg_replace('/[\r\n\t]+/', ',', $rawText);

        // Dividir por coma, punto y coma, o barra vertical
        $items = preg_split('/[,;|]+/u', $text);

        $result = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Aplica las reglas de limpieza a una etiqueta individual.
     *
     * @param string $tag
     * @return string|null La etiqueta limpia, o null si es inválida
     */
    private function cleanTag(string $tag): ?string
    {
        $tag = trim($tag);

        if ($tag === '') {
            return null;
        }

        // ── REGLA 1: Minúsculas ──
        $tag = mb_strtolower($tag, 'UTF-8');

        // ── REGLA 2: Reemplazar guiones por espacios ──
        // Los guiones entre palabras se convierten a espacios
        // Pero NO se debe romper modificadores con paréntesis (REGLA 3)
        // Ej: "ai-generated" → "ai generated"
        // Excepción: NO tocar guiones dentro de paréntesis como "(femenino)"
        // Separamos la etiqueta en: parte principal + modificador opcional entre paréntesis
        $modifier = '';
        if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/', $tag, $m)) {
            // Tiene un modificador al final tipo "oral (femenino)"
            $mainPart = trim($m[1]);
            $modifier = '(' . trim($m[2]) . ')';
        } else {
            $mainPart = $tag;
        }

        // Reemplazar guiones por espacios en la parte principal
        $mainPart = preg_replace('/[–—_-]+/u', ' ', $mainPart);
        $mainPart = preg_replace('/\s+/u', ' ', $mainPart);
        $mainPart = trim($mainPart);

        // Reconstruir con el modificador
        $tag = $mainPart;
        if ($modifier !== '') {
            $tag .= ' ' . $modifier;
        }

        // ── REGLA 4: Limpiar caracteres no permitidos ──
        // Permitir: letras (incluyendo acentos y ñ), números, espacios,
        // puntos (para siglas), paréntesis (para modificadores)
        $tag = preg_replace('/[^\p{L}\p{N}\s.()\-]/u', '', $tag);
        $tag = preg_replace('/\s+/u', ' ', $tag);
        $tag = trim($tag);

        // Eliminar puntos solitarios o secuencias sin sentido
        $tag = preg_replace('/\b\.\b/', '', $tag);

        if ($tag === '' || $tag === '.' || $tag === '-') {
            return null;
        }

        return $tag;
    }

    /**
     * Intenta emparejar una etiqueta procesada con la lista de existentes.
     *
     * Estrategia (orden de precedencia):
     *   1. MAPA DE EQUIVALENCIAS: consulta el diccionario tag_origen → tag_destino.
     *      Esto resuelve el caso de etiquetas en inglés que deben traducirse al español.
     *      Ej: "big breasts (female)" → "tetona"
     *   2. Búsqueda exacta normalizada (case-insensitive, espacios normalizados).
     *   3. Búsqueda por aproximación (similar_text) para variaciones ortográficas.
     *
     * @param string $tag Etiqueta ya limpia
     * @return string|null La forma canónica de la etiqueta existente, o null si no hay match
     */
    private function matchExisting(string $tag): ?string
    {
        $searchKey = TaxonomyData::normalizeForSearch($tag);

        // ── 1. MAPA DE EQUIVALENCIAS ──
        if (isset($this->tagMappings[$searchKey])) {
            return $this->tagMappings[$searchKey];
        }

        // ── 2. Búsqueda exacta normalizada ──
        if (isset($this->existingTags[$searchKey])) {
            return mb_strtolower($this->existingTags[$searchKey], 'UTF-8');
        }

        // ── 3. Intentar sin modificador entre paréntesis ──
        // Tags de 3hentai como "anal (male)" o "blowjob (male)" llevan
        // un modificador de género entre paréntesis. Si no hay match con
        // el tag completo, extraemos la parte base y reintentamos.
        $basePart = preg_replace('/\s*\([^)]+\)\s*$/', '', $searchKey);
        $basePart = trim($basePart);
        if ($basePart !== '' && $basePart !== $searchKey) {
            // Reintentar con la base (sin modificador)
            if (isset($this->tagMappings[$basePart])) {
                return $this->tagMappings[$basePart];
            }
            if (isset($this->existingTags[$basePart])) {
                return mb_strtolower($this->existingTags[$basePart], 'UTF-8');
            }
        }

        // ── 4. Búsqueda por similitud (fuzzy) ──
        // Strings de 1-2 caracteres NO deben hacer fuzzy match
        if (strlen($searchKey) < 3) {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0.0;

        // Umbral dinámico: strings cortos necesitan más exactitud
        $dynamicThreshold = $this->fuzzyThreshold;
        if (strlen($searchKey) < 5) {
            $dynamicThreshold = max($dynamicThreshold, 0.90);
        }

        foreach ($this->existingTags as $existingKey => $existingValue) {
            $score = 0.0;
            similar_text($searchKey, $existingKey, $score);
            $score /= 100.0;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $existingValue;
            }
        }

        if ($bestMatch !== null && $bestScore >= $dynamicThreshold) {
            return mb_strtolower($bestMatch, 'UTF-8');
        }

        return null;
    }

    /**
     * Registra en el archivo de log una etiqueta que no encontró equivalencia
     * ni en el mapa de mappings ni en la lista de etiquetas existentes.
     *
     * Esto permite al administrador revisar periódicamente qué tags del sitio
     * origen no están cubiertos y agregar nuevas entradas al diccionario.
     *
     * @param string $tag Etiqueta limpia que no se pudo mapear
     * @return void
     */
    private function logUnmappedTag(string $tag): void
    {
        // Evitar loguear tags vacíos o genéricos
        if ($tag === '' || $tag === null) {
            return;
        }

        $line = '[' . date('Y-m-d H:i:s') . '] TAG_NO_MAPEADO: "' . $tag . '"' . PHP_EOL;

        // Asegurar que el directorio de logs exista
        $logDir = dirname($this->unmappedLogFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        @file_put_contents(
            $this->unmappedLogFile,
            $line,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Retorna las etiquetas existentes en formato plano (para depuración).
     *
     * @return array<string>
     */
    public function getExistingTags(): array
    {
        return TaxonomyData::getTags();
    }

    /**
     * Retorna el mapa de equivalencias completo (para depuración).
     *
     * @return array<string, string>
     */
    public function getTagMappings(): array
    {
        return $this->tagMappings;
    }
}
