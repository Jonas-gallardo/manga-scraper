<?php
/**
 * WPTaxonomySync.php
 *
 * Sincroniza las taxonomías de la base de datos local con WordPress.
 * Para cada término (etiqueta, universo, idioma, tipo, autor, personaje),
 * se asegura de que exista en WordPress y retorna su ID numérico.
 *
 * MAPEO DE TAXONOMÍAS (local → WordPress):
 *   etiquetas  → post_tag      (taxonomía nativa de WordPress)
 *   universos  → universo       (taxonomía personalizada CPT UI)
 *   idioma     → idioma         (taxonomía personalizada CPT UI)
 *   tipos      → tipo           (taxonomía personalizada CPT UI)
 *   autores    → autor          (taxonomía personalizada CPT UI)
 *   personajes → personaje      (taxonomía personalizada CPT UI)
 *
 * @package ScrapApp
 * @subpackage WP
 */

class WPTaxonomySync
{
    /** @var WPClient */
    private WPClient $client;

    /**
     * Mapeo de claves de taxonomía local → slug de taxonomía en WordPress REST API.
     *
     * @var array<string, string>
     */
    private array $taxonomyMap = [
        'etiquetas'  => 'post_tag',
        'universos'  => 'universo',
        'idioma'     => 'idioma',
        'tipos'      => 'tipo',
        'autores'    => 'autor',
        'personajes' => 'personaje',
    ];

    /**
     * Cache de términos ya sincronizados: [taxonomy_slug][term_name] => id
     * Evita llamadas redundantes a la API.
     *
     * @var array<string, array<string, int>>
     */
    private array $cache = [];

    /**
     * @param WPClient $client Instancia de WPClient
     */
    public function __construct(WPClient $client)
    {
        $this->client = $client;
    }

    /**
     * Sincroniza TODAS las taxonomías de un cómic con WordPress.
     *
     * @param array<string, mixed> $taxData Datos de taxonomías locales
     *   (formato: ['idioma' => string|null, 'universos' => string[], 'tipos' => string[],
     *               'autores' => string[], 'personajes' => string[], 'etiquetas' => string[]])
     * @return array<string, mixed> IDs de taxonomías en WordPress:
     *   [
     *     'post_tag'  => [int, ...],
     *     'universo'  => [int, ...],
     *     'personaje' => [int, ...],
     *     'idioma'    => [int],
     *     'tipo'      => [int],
     *   ]
     * @throws RuntimeException
     */
    public function syncAll(array $taxData): array
    {
        $result = [
            'post_tag'  => [],
            'universo'  => [],
            'personaje' => [],
            'idioma'    => [],
            'tipo'      => [],
        ];

        // ── Idioma ──
        if (!empty($taxData['idioma'])) {
            $id = $this->syncTerm('idioma', $taxData['idioma']);
            if ($id !== null) {
                $result['idioma'] = [$id];
            }
        }

        // ── Universos ──
        if (!empty($taxData['universos']) && is_array($taxData['universos'])) {
            foreach ($taxData['universos'] as $universe) {
                $id = $this->syncTerm('universo', $universe);
                if ($id !== null) {
                    $result['universo'][] = $id;
                }
            }
        }

        // ── Tipos ──
        if (!empty($taxData['tipos']) && is_array($taxData['tipos'])) {
            foreach ($taxData['tipos'] as $tipo) {
                $id = $this->syncTerm('tipo', $tipo);
                if ($id !== null) {
                    $result['tipo'][] = $id;
                }
            }
        }

        // ── Autores → los mapeamos a la taxonomía 'autor' ──
        if (!empty($taxData['autores']) && is_array($taxData['autores'])) {
            foreach ($taxData['autores'] as $autor) {
                $id = $this->syncTerm('autor', $autor);
                if ($id !== null) {
                    // Los autores no van en el payload final del post según la especificación
                    // pero se registran en WordPress
                }
            }
        }

        // ── Personajes ──
        if (!empty($taxData['personajes']) && is_array($taxData['personajes'])) {
            foreach ($taxData['personajes'] as $personaje) {
                $id = $this->syncTerm('personaje', $personaje);
                if ($id !== null) {
                    $result['personaje'][] = $id;
                }
            }
        }

        // ── Etiquetas (post_tag) ──
        if (!empty($taxData['etiquetas']) && is_array($taxData['etiquetas'])) {
            foreach ($taxData['etiquetas'] as $tag) {
                $id = $this->syncTerm('post_tag', $tag);
                if ($id !== null) {
                    $result['post_tag'][] = $id;
                }
            }
        }

        return $result;
    }

    /**
     * Sincroniza un término individual con WordPress.
     * Usa cache para evitar llamadas repetidas a la API.
     *
     * @param string $wpTaxonomy Slug de taxonomía en WordPress (ej: 'post_tag', 'universo')
     * @param string $termName Nombre del término
     * @return int|null ID del término en WordPress, o null si falla
     */
    public function syncTerm(string $wpTaxonomy, string $termName): ?int
    {
        $termName = trim($termName);
        if ($termName === '') {
            return null;
        }

        // ── Verificar cache ──
        if (isset($this->cache[$wpTaxonomy][$termName])) {
            return $this->cache[$wpTaxonomy][$termName];
        }

        try {
            $id = $this->client->ensureTermExists($wpTaxonomy, $termName);
            // Guardar en cache
            if (!isset($this->cache[$wpTaxonomy])) {
                $this->cache[$wpTaxonomy] = [];
            }
            $this->cache[$wpTaxonomy][$termName] = $id;
            return $id;
        } catch (RuntimeException $e) {
            // Loggear error pero no detener el proceso
            $this->logError("Error sincronizando término '{$termName}' en '{$wpTaxonomy}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convierte el array de IDs de taxonomías al formato esperado
     * por el payload del post de WordPress.
     *
     * @param array<string, mixed> $syncedIds Resultado de syncAll()
     * @return array<string, mixed> Datos listos para inyectar en el payload
     */
    public function buildTaxonomyPayload(array $syncedIds): array
    {
        $payload = [];

        // post_tag: array de IDs → WordPress REST API espera 'tags' no 'post_tag'
        if (!empty($syncedIds['post_tag'])) {
            $payload['tags'] = $syncedIds['post_tag'];
        }

        // universo: primer ID (o array)
        if (!empty($syncedIds['universo'])) {
            $payload['universo'] = $syncedIds['universo'];
        }

        // personaje: array de IDs
        if (!empty($syncedIds['personaje'])) {
            $payload['personaje'] = $syncedIds['personaje'];
        }

        // idioma: primer ID
        if (!empty($syncedIds['idioma'])) {
            $payload['idioma'] = $syncedIds['idioma'][0];
        }

        // tipo: primer ID
        if (!empty($syncedIds['tipo'])) {
            $payload['tipo'] = $syncedIds['tipo'];
        }

        return $payload;
    }

    /**
     * Obtiene el slug de taxonomía de WordPress a partir de la clave local.
     *
     * @param string $localKey Clave local (ej: 'etiquetas')
     * @return string|null Slug de WordPress
     */
    public function getTaxonomySlug(string $localKey): ?string
    {
        return $this->taxonomyMap[$localKey] ?? null;
    }

    /**
     * Retorna el mapa completo de taxonomías.
     *
     * @return array<string, string>
     */
    public function getTaxonomyMap(): array
    {
        return $this->taxonomyMap;
    }

    /**
     * Reinicia la cache de términos sincronizados.
     * Útil al comenzar un nuevo lote de publicaciones.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    // ─────────────────────────────────────────────────────────────
    //  LOGGING
    // ─────────────────────────────────────────────────────────────

    /**
     * Registra un error en el log del sistema.
     *
     * @param string $message
     */
    private function logError(string $message): void
    {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/wp_publisher.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [ERROR] ' . $message . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
