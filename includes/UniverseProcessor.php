<?php
/**
 * UniverseProcessor.php
 *
 * Procesa universos (series) extraídos de la fuente de scraping.
 * Aplica las reglas estrictas de limpieza y normalización para la
 * taxonomía personalizada "Universos" de Gluglux (WordPress CPT UI).
 *
 * Reglas:
 *   1. Minúsculas: Títulos estrictamente en minúsculas sin mayúsculas iniciales.
 *   2. Espaciado: Mantener espacios naturales entre palabras.
 *   3. Símbolos y Números: Permitir que los nombres inicien con puntos o números
 *      (ej. ".hack", "11eyes").
 *   4. Manejo de Alias (CRÍTICO): El símbolo "|" separa alias.
 *      Al mapear con WordPress, debe buscar coincidencias con cualquiera de los alias.
 *
 * @package ScrapApp
 * @subpackage Taxonomy
 */

require_once __DIR__ . '/TaxonomyData.php';

class UniverseProcessor
{
    /**
     * Lista de universos existentes en WordPress (forma normalizada).
     *
     * @var array<string, string>  normalized_key => original_value
     */
    private array $existingUniverses;

    /**
     * Umbral de similitud para fuzzy matching (0.0 - 1.0).
     *
     * @var float
     */
    private float $fuzzyThreshold;

    /**
     * @param float $fuzzyThreshold Umbral de similitud (default 0.75)
     */
    public function __construct(float $fuzzyThreshold = 0.75)
    {
        $this->existingUniverses = TaxonomyData::getUniversesNormalized();
        $this->fuzzyThreshold = $fuzzyThreshold;
    }

    /**
     * Procesa el nombre de un universo (serie) extraído de la fuente.
     *
     @param string|null $rawUniverse Texto crudo del universo (puede incluir alias con "|")
     * @return string|null El universo normalizado y emparejado con WordPress,
     *                     o el nombre limpio si no hay match, o null si está vacío
     */
    public function process(?string $rawUniverse): ?string
    {
        if ($rawUniverse === null || trim($rawUniverse) === '') {
            return null;
        }

        // ── REGLA 4 (ALIAS): Dividir por "|" ──
        $aliases = $this->splitAliases($rawUniverse);

        if (empty($aliases)) {
            return null;
        }

        // 1. Limpiar cada alias
        $cleanedAliases = [];
        foreach ($aliases as $alias) {
            $cleaned = $this->cleanUniverseName($alias);
            if ($cleaned !== null) {
                $cleanedAliases[] = $cleaned;
            }
        }

        if (empty($cleanedAliases)) {
            return null;
        }

        // 2. Eliminar duplicados internos
        $cleanedAliases = array_unique($cleanedAliases);

        // 3. Buscar match con WordPress para CADA alias
        foreach ($cleanedAliases as $alias) {
            $matched = $this->matchExisting($alias);
            if ($matched !== null) {
                // Encontramos match → devolver el nombre canónico de WordPress
                return $matched;
            }
        }

        // 4. Sin match en WordPress → devolver el primer alias limpio
        return $cleanedAliases[0];
    }

    /**
     * Procesa múltiples universos (array). Útil cuando un cómic pertenece
     * a varios universos.
     *
     * @param array<string>|string|null $rawUniverses Array de strings o string único
     * @return array<string> Universos normalizados
     */
    public function processMultiple($rawUniverses): array
    {
        if ($rawUniverses === null) {
            return [];
        }

        if (is_string($rawUniverses)) {
            // Si es un string, podría tener múltiples universos separados por coma
            $parts = preg_split('/[,;]+/', $rawUniverses);
            $results = [];
            foreach ($parts as $part) {
                $result = $this->process(trim($part));
                if ($result !== null) {
                    $results[] = $result;
                }
            }
            return array_values(array_unique($results));
        }

        if (is_array($rawUniverses)) {
            $results = [];
            foreach ($rawUniverses as $universe) {
                $result = $this->process($universe);
                if ($result !== null) {
                    $results[] = $result;
                }
            }
            return array_values(array_unique($results));
        }

        return [];
    }

    /**
     * Divide un string de universo en alias usando "|" como separador.
     *
     * @param string $text Ej: "romaji | english" → ["romaji", "english"]
     * @return array<string>
     */
    private function splitAliases(string $text): array
    {
        // Dividir por el símbolo "|" (con o sin espacios alrededor)
        $parts = preg_split('/\s*\|\s*/u', $text);

        $aliases = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $aliases[] = $part;
            }
        }

        return $aliases;
    }

    /**
     * Limpia un nombre de universo aplicando las reglas de formato.
     *
     * @param string $name
     * @return string|null
     */
    private function cleanUniverseName(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        // ── REGLA 1: Minúsculas ──
        $name = mb_strtolower($name, 'UTF-8');

        // ── REGLA 2: Mantener espacios naturales ──
        // Reemplazar múltiples espacios por uno solo
        $name = preg_replace('/\s+/u', ' ', $name);

        // ── REGLA 3: Permitir puntos y números al inicio ──
        // Solo limpiar caracteres PROBLEMÁTICOS que no sean letras, números,
        // espacios, puntos, guiones (para compuestos tipo "wall-e")
        // Pero mantener el punto inicial si existe (ej: ".hack")
        $name = preg_replace('/[^\p{L}\p{N}\s.\-]/u', '', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '-') {
            return null;
        }

        return $name;
    }

    /**
     * Busca el nombre canónico en WordPress para un universo dado.
     *
     * Estrategia:
     *   1. Búsqueda exacta normalizada.
     *   2. Búsqueda por similitud (similar_text).
     *   3. Substring match: si el nombre está contenido dentro de uno existente.
     *
     * @param string $universeName Nombre ya limpiado
     * @return string|null Nombre canónico o null
     */
    private function matchExisting(string $universeName): ?string
    {
        $searchKey = TaxonomyData::normalizeForSearch($universeName);

        // ── 1. Búsqueda exacta normalizada ──
        if (isset($this->existingUniverses[$searchKey])) {
            // Devolver siempre en minúsculas (regla estricta #1)
            return mb_strtolower($this->existingUniverses[$searchKey], 'UTF-8');
        }

        // ── 2. Búsqueda por similitud (fuzzy) ──
        $bestMatch = null;
        $bestScore = 0.0;

        foreach ($this->existingUniverses as $existingKey => $existingValue) {
            $score = 0.0;
            similar_text($searchKey, $existingKey, $score);
            $score /= 100.0;

            // Bono por substring match
            if ($score < 1.0) {
                if (strpos($existingKey, $searchKey) !== false || strpos($searchKey, $existingKey) !== false) {
                    $score = max($score, 0.85);
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $existingValue;
            }
        }

        if ($bestMatch !== null && $bestScore >= $this->fuzzyThreshold) {
            // Devolver siempre en minúsculas
            return mb_strtolower($bestMatch, 'UTF-8');
        }

        return null;
    }

    /**
     * Retorna los universos existentes (para depuración).
     *
     * @return array<string>
     */
    public function getExistingUniverses(): array
    {
        return TaxonomyData::getUniverses();
    }
}
