<?php
/**
 * src/Controllers/ExportController.php
 *
 * Controller for exporting taxonomies to JSON format,
 * compatible with WordPress import.
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class ExportController extends BaseController
{
    /**
     * Handle export requests.
     * GET ?download=1         → Download JSON file
     * GET ?format=wp-rest     → WordPress REST API format
     * GET ?comic_id=123       → Export single comic
     * GET (no params)         → Show HTML summary page
     */
    public function handle(): void
    {
        try {
            $pdo = $this->getPDO();

            $format   = $this->getParam('format', 'default');
            $download = isset($_GET['download']);
            $comic_id = $this->getParam('comic_id') ? (int) $this->getParam('comic_id') : null;

            // ── Build query ──
            if ($comic_id) {
                $stmt = $pdo->prepare(
                    'SELECT id_fuente, titulo, universo, autor, taxonomias
                     FROM comics_descargados
                     WHERE id_fuente = ? AND taxonomias IS NOT NULL AND JSON_VALID(taxonomias)'
                );
                $stmt->execute([$comic_id]);
            } else {
                $stmt = $pdo->query(
                    'SELECT id_fuente, titulo, universo, autor, taxonomias
                     FROM comics_descargados
                     WHERE taxonomias IS NOT NULL AND JSON_VALID(taxonomias)
                     ORDER BY id_fuente'
                );
            }

            $comics = $stmt->fetchAll();

            // ── Build unique taxonomy lists ──
            $allTags       = [];
            $allUniverses  = [];
            $allIdiomas    = [];
            $allTipos      = [];
            $allAutores    = [];
            $allPersonajes = [];

            foreach ($comics as &$comic) {
                $tax = json_decode($comic['taxonomias'], true);
                if (!is_array($tax)) {
                    $tax = [];
                }
                $comic['taxonomias_parsed'] = $tax;

                if (!empty($tax['etiquetas'])) {
                    foreach ($tax['etiquetas'] as $t) {
                        $key = mb_strtolower(trim($t));
                        if ($key !== '') {
                            $allTags[$key] = ['name' => $t, 'slug' => $this->sanitizeSlug($t)];
                        }
                    }
                }

                if (!empty($tax['universos'])) {
                    foreach ($tax['universos'] as $u) {
                        $key = mb_strtolower(trim($u));
                        if ($key !== '') {
                            $allUniverses[$key] = ['name' => $u, 'slug' => $this->sanitizeSlug($u)];
                        }
                    }
                }

                if (!empty($tax['idioma'])) {
                    $key = mb_strtolower(trim($tax['idioma']));
                    $allIdiomas[$key] = ['name' => $tax['idioma'], 'slug' => $this->sanitizeSlug($tax['idioma'])];
                }

                if (!empty($tax['tipos'])) {
                    foreach ($tax['tipos'] as $tp) {
                        $key = mb_strtolower(trim($tp));
                        if ($key !== '') {
                            $allTipos[$key] = ['name' => $tp, 'slug' => $this->sanitizeSlug($tp)];
                        }
                    }
                }

                if (!empty($tax['autores'])) {
                    foreach ($tax['autores'] as $a) {
                        $key = mb_strtolower(trim($a));
                        if ($key !== '') {
                            $allAutores[$key] = ['name' => $a, 'slug' => $this->sanitizeSlug($a)];
                        }
                    }
                }

                if (!empty($tax['personajes'])) {
                    foreach ($tax['personajes'] as $p) {
                        $key = mb_strtolower(trim($p));
                        if ($key !== '') {
                            $allPersonajes[$key] = ['name' => $p, 'slug' => $this->sanitizeSlug($p)];
                        }
                    }
                }
            }
            unset($comic);

            // ── Build output payload ──
            $payload = [
                'site'      => 'Gluglux',
                'generated' => date('c'),
                'total_comics' => count($comics),
                'taxonomies' => [
                    'etiquetas'  => array_values($allTags),
                    'universos'  => array_values($allUniverses),
                    'idiomas'    => array_values($allIdiomas),
                    'tipos'      => array_values($allTipos),
                    'autores'    => array_values($allAutores),
                    'personajes' => array_values($allPersonajes),
                ],
                'comics' => [],
            ];

            foreach ($comics as $comic) {
                $payload['comics'][] = [
                    'id_fuente'  => (int) $comic['id_fuente'],
                    'titulo'     => $comic['titulo'],
                    'universo'   => $comic['universo'],
                    'autor'      => $comic['autor'],
                    'taxonomias' => $comic['taxonomias_parsed'],
                ];
            }

            // ── WP REST API format ──
            if ($format === 'wp-rest') {
                $wpPosts = [];
                foreach ($comics as $comic) {
                    $tax = $comic['taxonomias_parsed'];
                    $wpPost = [
                        'post_title'   => $comic['titulo'],
                        'post_status'  => 'publish',
                        'post_type'    => 'comic',
                        'meta_input'   => ['id_fuente' => (int) $comic['id_fuente']],
                        'taxonomy_input' => [],
                    ];

                    if (!empty($tax['etiquetas'])) {
                        $wpPost['taxonomy_input']['etiquetas'] = array_map([$this, 'sanitizeSlug'], $tax['etiquetas']);
                    }
                    if (!empty($tax['universos'])) {
                        $wpPost['taxonomy_input']['universos'] = array_map([$this, 'sanitizeSlug'], $tax['universos']);
                    }
                    if (!empty($tax['idioma'])) {
                        $wpPost['taxonomy_input']['idiomas'] = [$this->sanitizeSlug($tax['idioma'])];
                    }
                    if (!empty($tax['tipos'])) {
                        $wpPost['taxonomy_input']['tipos'] = array_map([$this, 'sanitizeSlug'], $tax['tipos']);
                    }
                    if (!empty($tax['autores'])) {
                        $wpPost['taxonomy_input']['autores'] = array_map([$this, 'sanitizeSlug'], $tax['autores']);
                    }
                    if (!empty($tax['personajes'])) {
                        $wpPost['taxonomy_input']['personajes'] = array_map([$this, 'sanitizeSlug'], $tax['personajes']);
                    }

                    $wpPosts[] = $wpPost;
                }

                $payload = [
                    'site'      => 'Gluglux',
                    'generated' => date('c'),
                    'total'     => count($wpPosts),
                    'posts'     => $wpPosts,
                ];
            }

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            // ── Download mode ──
            if ($download) {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="gluglux-export-' . date('Ymd-His') . '.json"');
                header('Content-Length: ' . strlen($json));
                echo $json;
                exit;
            }

            // ── HTML summary view ──
            $this->showHtmlView($payload, $comics, $allTags, $allUniverses, $allIdiomas, $allAutores);

        } catch (\Exception $e) {
            if (isset($_GET['download'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            } else {
                echo '<html><body style="background:#080b12;color:#f85149;font-family:sans-serif;padding:2rem;">';
                echo '<h1>Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</body></html>';
            }
            exit;
        }
    }

    /**
     * Render the HTML summary view.
     */
    private function showHtmlView(array $payload, array $comics, array $allTags, array $allUniverses, array $allIdiomas, array $allAutores): void
    {
        $totalComics = count($comics);
        $totalTags = count($allTags);
        $totalUniverses = count($allUniverses);
        $totalIdiomas = count($allIdiomas);
        $totalAutores = count($allAutores);
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Exportar Taxonomías — Gluglux</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                body { font-family: 'Inter', system-ui, sans-serif; background: #080b12; color: #e2e8f0; }
                .glass { background: rgba(22, 27, 34, 0.75); backdrop-filter: blur(12px); border: 1px solid rgba(48, 54, 61, 0.5); border-radius: 1rem; }
                .btn-glow { padding: 0.625rem 1.5rem; border-radius: 0.75rem; font-weight: 600; background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; color: white; cursor: pointer; transition: all 0.25s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; }
                .btn-glow:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(99, 102, 241, 0.35); }
                .btn-ghost { padding: 0.625rem 1.25rem; border-radius: 0.75rem; font-weight: 500; color: #94a3b8; background: rgba(30, 35, 45, 0.6); border: 1px solid rgba(48, 54, 61, 0.4); cursor: pointer; text-decoration: none; transition: all 0.2s; }
                .btn-ghost:hover { background: rgba(40, 46, 56, 0.8); color: #e2e8f0; }
                .stat-value { font-size: 1.75rem; font-weight: 800; margin-top: 0.25rem; }
                pre { background: #0a0d14; border: 1px solid rgba(48, 54, 61, 0.3); border-radius: 0.75rem; padding: 1rem; font-size: 0.75rem; max-height: 400px; overflow: auto; white-space: pre-wrap; word-break: break-all; }
            </style>
        </head>
        <body class="min-h-screen p-6">
            <div class="max-w-4xl mx-auto">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h1 class="text-2xl font-bold text-white">📦 Exportar Taxonomías</h1>
                        <p class="text-gray-500 text-sm mt-1">Datos listos para importar en WordPress (Gluglux)</p>
                    </div>
                    <a href="index.php" class="btn-ghost text-sm">⬅ Volver</a>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                    <div class="glass p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wider">Cómics</p>
                        <p class="stat-value text-indigo-400"><?= $totalComics ?></p>
                    </div>
                    <div class="glass p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wider">Etiquetas</p>
                        <p class="stat-value text-emerald-400"><?= $totalTags ?></p>
                    </div>
                    <div class="glass p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wider">Universos</p>
                        <p class="stat-value text-amber-400"><?= $totalUniverses ?></p>
                    </div>
                    <div class="glass p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wider">Idiomas</p>
                        <p class="stat-value text-blue-400"><?= $totalIdiomas ?></p>
                    </div>
                    <div class="glass p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wider">Autores</p>
                        <p class="stat-value text-purple-400"><?= $totalAutores ?></p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3 mb-6">
                    <a href="?download=1" class="btn-glow text-sm">⬇ Descargar JSON estándar</a>
                    <a href="?format=wp-rest&download=1" class="btn-glow text-sm">⬇ Descargar para WP REST API</a>
                    <a href="?format=wp-rest" class="btn-ghost text-sm">👁 Vista WP REST</a>
                </div>

                <div class="glass p-5">
                    <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Vista previa del JSON</h3>
                    <pre><?php
                        $preview = $payload;
                        if (isset($preview['comics']) && count($preview['comics']) > 3) {
                            $preview['comics'] = array_slice($preview['comics'], 0, 3);
                            $preview['_notice'] = 'Mostrando solo 3 de ' . $totalComics . ' cómics.';
                        }
                        if (isset($preview['posts']) && count($preview['posts']) > 3) {
                            $preview['posts'] = array_slice($preview['posts'], 0, 3);
                            $preview['_notice'] = 'Mostrando solo 3 de ' . $totalComics . ' posts.';
                        }
                        echo htmlspecialchars(json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    ?></pre>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Generate a clean slug for WordPress.
     */
    private function sanitizeSlug(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[()]/u', '', $text);
        $text = preg_replace('/[^a-z0-9áéíóúüñ\-.]+/u', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        $text = trim($text, '-');
        return $text;
    }
}
