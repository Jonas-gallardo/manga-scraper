<?php
/**
 * TaxonomyProcessor.php
 *
 * ORQUESTADOR PRINCIPAL del módulo de taxonomías para Gluglux.
 *
 * Integra todos los procesadores individuales:
 *   - TagProcessor      → Limpieza y validación de etiquetas
 *   - UniverseProcessor → Limpieza y validación de universos (con alias)
 *   - LanguageProcessor → Normalización de idiomas
 *
 * Responsabilidad:
 *   Recibir datos crudos extraídos del scraping y devolver un objeto/JSON
 *   estructurado y estandarizado listo para ser enviado a la REST API
 *   de WordPress en la siguiente fase.
 *
 * Salida esperada:
 *   {
 *     "idioma": "español",
 *     "universos": ["attack on titan"],
 *     "tipos": ["comic"],
 *     "autores": ["nombre del artista"],
 *     "personajes": ["shinobu kochou"],
 *     "etiquetas": ["3d", "pelo corto", "oral (femenino)"]
 *   }
 *
 * @package ScrapApp
 * @subpackage Taxonomy
 */

require_once __DIR__ . '/TagProcessor.php';
require_once __DIR__ . '/UniverseProcessor.php';
require_once __DIR__ . '/LanguageProcessor.php';

class TaxonomyProcessor
{
    private TagProcessor $tagProcessor;
    private UniverseProcessor $universeProcessor;
    private LanguageProcessor $languageProcessor;

    /**
     * Configuración por defecto para tipos.
     *
     * @var array<string, mixed>
     */
    private array $defaults;

    /**
     * @param array<string, mixed> $options Opciones de configuración
     */
    public function __construct(array $options = [])
    {
        $this->tagProcessor      = new TagProcessor($options['fuzzy_threshold'] ?? 0.75);
        $this->universeProcessor = new UniverseProcessor($options['fuzzy_threshold'] ?? 0.75);
        $this->languageProcessor = new LanguageProcessor();

        $this->defaults = [
            'tipo'        => $options['default_tipo'] ?? 'comic',
            'fuzzy_tags'  => $options['fuzzy_threshold'] ?? 0.75,
            'fuzzy_universes' => $options['fuzzy_threshold'] ?? 0.75,
        ];
    }

    /**
     * Método principal: procesa datos crudos de scraping y devuelve
     * un array estructurado con todas las taxonomías normalizadas.
     *
     * @param array<string, mixed> $rawData Datos crudos del scraper:
     *   [
     *     'tags'      => string|null  (etiquetas separadas por coma)
     *     'universo'  => string|null  (nombre del universo, puede incluir |)
     *     'idioma'    => string|null  (código o nombre del idioma)
     *     'autor'     => string|null  (nombre del artista/author)
     *     'tipo'      => string|null  (tipo de contenido, null → usa default)
     *   ]
     *
     * @return array<string, mixed> Taxonomías procesadas:
     *   [
     *     'idioma'     => string|null
     *     'universos'  => string[]
     *     'tipos'      => string[]
     *     'autores'    => string[]
     *     'personajes' => string[]
     *     'etiquetas'  => string[]
     *   ]
     */
    public function process(array $rawData): array
    {
        $result = [
            'idioma'     => null,
            'universos'  => [],
            'tipos'      => [],
            'autores'    => [],
            'personajes' => [],
            'etiquetas'  => [],
        ];

        // ── 1. Procesar Idioma ──
        $result['idioma'] = $this->processLanguage($rawData['idioma'] ?? null);

        // ── 2. Procesar Universos ──
        $result['universos'] = $this->processUniverses($rawData['universo'] ?? null);

        // ── 3. Procesar Tipos ──
        $result['tipos'] = $this->processTypes($rawData['tipo'] ?? null);

        // ── 4. Procesar Autores ──
        $result['autores'] = $this->processAuthors($rawData['autor'] ?? null);

        // ── 5. Procesar Personajes ──
        $result['personajes'] = $this->processPersonajes($rawData['personajes'] ?? null);

        // ── 6. Procesar Etiquetas ──
        $result['etiquetas'] = $this->processTags($rawData['tags'] ?? null);

        return $result;
    }

    /**
     * Procesa y normaliza el idioma.
     *
     * @param string|null $rawLanguage
     * @return string|null
     */
    private function processLanguage(?string $rawLanguage): ?string
    {
        return $this->languageProcessor->process($rawLanguage);
    }

    /**
     * Procesa y normaliza los universos.
     *
     * @param string|array|null $rawUniverses
     * @return array<string>
     */
    private function processUniverses($rawUniverses): array
    {
        return $this->universeProcessor->processMultiple($rawUniverses);
    }

    /**
     * Procesa los tipos de contenido.
     *
     * Tipos: Esta taxonomía requerirá selección manual por el usuario.
     * Si no se provee, usa el valor por defecto configurado.
     *
     * @param string|array|null $rawTypes
     * @return array<string>
     */
    private function processTypes($rawTypes): array
    {
        if ($rawTypes === null || (is_string($rawTypes) && trim($rawTypes) === '')) {
            // Usar valor por defecto
            return [mb_strtolower($this->defaults['tipo'], 'UTF-8')];
        }

        if (is_string($rawTypes)) {
            // Soporta múltiples tipos separados por coma
            $parts = preg_split('/[,;]+/', $rawTypes);
            $types = [];
            foreach ($parts as $part) {
                $part = trim(mb_strtolower($part, 'UTF-8'));
                if ($part !== '') {
                    $types[] = $part;
                }
            }
            return array_values(array_unique($types));
        }

        if (is_array($rawTypes)) {
            $types = [];
            foreach ($rawTypes as $type) {
                $type = trim(mb_strtolower((string)$type, 'UTF-8'));
                if ($type !== '') {
                    $types[] = $type;
                }
            }
            return array_values(array_unique($types));
        }

        return [mb_strtolower($this->defaults['tipo'], 'UTF-8')];
    }

    /**
     * Procesa los autores/artistas.
     *
     * Autores: Extraer si está disponible; si no, dejar array vacío.
     *
     * @param string|array|null $rawAuthors
     * @return array<string>
     */
    private function processAuthors($rawAuthors): array
    {
        if ($rawAuthors === null) {
            return [];
        }

        if (is_string($rawAuthors)) {
            $rawAuthors = trim($rawAuthors);
            if ($rawAuthors === '') {
                return [];
            }

            // Soporta múltiples autores separados por coma, punto y coma, o "&"
            $parts = preg_split('/[,;&]+/u', $rawAuthors);
            $authors = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $authors[] = $part;
                }
            }
            return array_values(array_unique($authors));
        }

        if (is_array($rawAuthors)) {
            $authors = [];
            foreach ($rawAuthors as $author) {
                $author = trim((string)$author);
                if ($author !== '') {
                    $authors[] = $author;
                }
            }
            return array_values(array_unique($authors));
        }

        return [];
    }

    /**
     * Procesa y normaliza los personajes (nombres de personajes).
     * Similar a processAuthors pero con limpieza básica.
     *
     * @param string|array|null $rawPersonajes
     * @return array<string>
     */
    private function processPersonajes($rawPersonajes): array
    {
        if ($rawPersonajes === null) {
            return [];
        }

        if (is_string($rawPersonajes)) {
            $rawPersonajes = trim($rawPersonajes);
            if ($rawPersonajes === '') {
                return [];
            }

            // Soporta múltiples personajes separados por coma
            $parts = preg_split('/[,;]+/u', $rawPersonajes);
            $personajes = [];
            foreach ($parts as $part) {
                $part = trim(mb_strtolower($part, 'UTF-8'));
                if ($part !== '') {
                    $personajes[] = $part;
                }
            }
            return array_values(array_unique($personajes));
        }

        if (is_array($rawPersonajes)) {
            $personajes = [];
            foreach ($rawPersonajes as $personaje) {
                $personaje = trim(mb_strtolower((string)$personaje, 'UTF-8'));
                if ($personaje !== '') {
                    $personajes[] = $personaje;
                }
            }
            return array_values(array_unique($personajes));
        }

        return [];
    }

    /**
     * Procesa y normaliza las etiquetas.
     *
     * @param string|null $rawTags
     * @return array<string>
     */
    private function processTags(?string $rawTags): array
    {
        return $this->tagProcessor->process($rawTags);
    }

    /**
     * Devuelve el resultado como JSON.
     *
     * @param array<string, mixed> $rawData
     * @param int $jsonOptions Opciones de JSON (default: JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
     * @return string
     */
    public function processToJson(array $rawData, int $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT): string
    {
        $result = $this->process($rawData);
        return json_encode($result, $jsonOptions);
    }

    /**
     * Procesa datos extraídos desde el scraper existente.
     * Mapea los campos del scraper a los esperados por este procesador.
     *
     * @param array<string, mixed> $scraperData Datos del scraper (con keys como 'tags', 'universo', etc.)
     * @return array<string, mixed>
     */
    public function processFromScraper(array $scraperData): array
    {
        $mapped = [
            'tags'       => $scraperData['tags'] ?? null,
            'universo'   => $scraperData['universo'] ?? null,
            'idioma'     => $scraperData['idioma'] ?? null,
            'autor'      => $scraperData['autor'] ?? $scraperData['artista'] ?? null,
            'tipo'       => $scraperData['tipo'] ?? null,
            'personajes' => $scraperData['personajes'] ?? null,
        ];

        return $this->process($mapped);
    }

    // ─────────────────────────────────────────────────────────────
    //  GETTERS DE PROCESADORES (para uso directo si es necesario)
    // ─────────────────────────────────────────────────────────────

    public function getTagProcessor(): TagProcessor
    {
        return $this->tagProcessor;
    }

    public function getUniverseProcessor(): UniverseProcessor
    {
        return $this->universeProcessor;
    }

    public function getLanguageProcessor(): LanguageProcessor
    {
        return $this->languageProcessor;
    }
}
