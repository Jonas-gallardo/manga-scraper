<?php
/**
 * export_wp.php
 *
 * Genera un archivo JSON con todas las taxonomías procesadas,
 * listo para ser importado en WordPress mediante la REST API
 * o plugins como WP All Import / CPT UI.
 *
 * USO:
 *   export_wp.php                     → Vista HTML con resumen y botón de descarga
 *   export_wp.php?download=1          → Descarga directa del JSON
 *   export_wp.php?format=wp-rest      → Formato compatible con WordPress REST API
 *   export_wp.php?comic_id=123        → Exportar solo un cómic específico
 *
 * ESTRUCTURA DEL JSON DE SALIDA:
 *   {
 *     "site": "Gluglux",
 *     "generated": "2026-01-01T12:00:00Z",
 *     "total_comics": 50,
 *     "taxonomies": {
 *       "etiquetas":  [ ... ],
 *       "universos":  [ ... ],
 *       "idiomas":    [ ... ],
 *       "tipos":      [ ... ],
 *       "autores":    [ ... ],
 *       "personajes": [ ... ]
 *     },
 *     "comics": [
 *       {
 *         "id_fuente": 605249,
 *         "titulo": "...",
 *         "taxonomias": { idioma, universos, tipos, autores, etiquetas }
 *       }
 *     ]
 *   }
 *
 * @package ScrapApp
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';

$format   = $_GET['format'] ?? 'default';
$download = isset($_GET['download']);
$comic_id = isset($_GET['comic_id']) ? (int) $_GET['comic_id'] : null;

try {
    // ── Construir consulta ──
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

    // ── Construir listas de taxonomías únicas ──
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

        // Acumular etiquetas
        if (!empty($tax['etiquetas'])) {
            foreach ($tax['etiquetas'] as $t) {
                $key = mb_strtolower(trim($t));
                if ($key !== '') {
                    $allTags[$key] = ['name' => $t, 'slug' => sanitize_slug($t)];
                }
            }
        }

        // Acumular universos
        if (!empty($tax['universos'])) {
            foreach ($tax['universos'] as $u) {
                $key = mb_strtolower(trim($u));
                if ($key !== '') {
                    $allUniverses[$key] = ['name' => $u, 'slug' => sanitize_slug($u)];
                }
            }
        }

        // Acumular idiomas
        if (!empty($tax['idioma'])) {
            $key = mb_strtolower(trim($tax['idioma']));
            $allIdiomas[$key] = ['name' => $tax['idioma'], 'slug' => sanitize_slug($tax['idioma'])];
        }

        // Acumular tipos
        if (!empty($tax['tipos'])) {
            foreach ($tax['tipos'] as $tp) {
                $key = mb_strtolower(trim($tp));
                if ($key !== '') {
                    $allTipos[$key] = ['name' => $tp, 'slug' => sanitize_slug($tp)];
                }
            }
        }

        // Acumular autores
        if (!empty($tax['autores'])) {
            foreach ($tax['autores'] as $a) {
                $key = mb_strtolower(trim($a));
                if ($key !== '') {
                    $allAutores[$key] = ['name' => $a, 'slug' => sanitize_slug($a)];
                }
            }
        }

        // Acumular personajes
        if (!empty($tax['personajes'])) {
            foreach ($tax['personajes'] as $p) {
                $key = mb_strtolower(trim($p));
                if ($key !== '') {
                    $allPersonajes[$key] = ['name' => $p, 'slug' => sanitize_slug($p)];
                }
            }
        }
    }
    unset($comic);

    // ── Construir payload de salida ──
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

    // ── Si es formato WP REST, transformar ──
    if ($format === 'wp-rest') {
        $wpPosts = [];
        foreach ($comics as $comic) {
            $tax = $comic['taxonomias_parsed'];
            $wpPost = [
                'post_title'   => $comic['titulo'],
                'post_status'  => 'publish',
                'post_type'    => 'comic',  // Custom post type name
                'meta_input'   => [
                    'id_fuente' => (int) $comic['id_fuente'],
                ],
                'taxonomy_input' => [],
            ];

            // Mapear taxonomías a los slugs de CPT UI
            if (!empty($tax['etiquetas'])) {
                $wpPost['taxonomy_input']['etiquetas'] = [];
                foreach ($tax['etiquetas'] as $t) {
                    $wpPost['taxonomy_input']['etiquetas'][] = sanitize_slug($t);
                }
            }
            if (!empty($tax['universos'])) {
                $wpPost['taxonomy_input']['universos'] = [];
                foreach ($tax['universos'] as $u) {
                    $wpPost['taxonomy_input']['universos'][] = sanitize_slug($u);
                }
            }
            if (!empty($tax['idioma'])) {
                $wpPost['taxonomy_input']['idiomas'] = [sanitize_slug($tax['idioma'])];
            }
            if (!empty($tax['tipos'])) {
                $wpPost['taxonomy_input']['tipos'] = [];
                foreach ($tax['tipos'] as $tp) {
                    $wpPost['taxonomy_input']['tipos'][] = sanitize_slug($tp);
                }
            }
            if (!empty($tax['autores'])) {
                $wpPost['taxonomy_input']['autores'] = [];
                foreach ($tax['autores'] as $a) {
                    $wpPost['taxonomy_input']['autores'][] = sanitize_slug($a);
                }
            }

            if (!empty($tax['personajes'])) {
                $wpPost['taxonomy_input']['personajes'] = [];
                foreach ($tax['personajes'] as $p) {
                    $wpPost['taxonomy_input']['personajes'][] = sanitize_slug($p);
                }
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

    // ── Salida ──
    if ($download) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="gluglux-export-' . date('Ymd-His') . '.json"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    // ── Vista HTML de resumen ──
    ?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar Taxonomías — Gluglux</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; background: #080b12; color: #e2e8f0; }
        .glass {
            background: rgba(22, 27, 34, 0.75);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(48, 54, 61, 0.5);
            border-radius: 1rem;
        }
        .btn-glow {
            padding: 0.625rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border: none;
            color: white;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-glow:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(99, 102, 241, 0.35); }
        .btn-ghost {
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 500;
            color: #94a3b8;
            background: rgba(30, 35, 45, 0.6);
            border: 1px solid rgba(48, 54, 61, 0.4);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        .btn-ghost:hover { background: rgba(40, 46, 56, 0.8); color: #e2e8f0; }
        .stat-value { font-size: 1.75rem; font-weight: 800; margin-top: 0.25rem; }
        pre {
            background: #0a0d14;
            border: 1px solid rgba(48, 54, 61, 0.3);
            border-radius: 0.75rem;
            padding: 1rem;
            font-size: 0.75rem;
            max-height: 400px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
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

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="glass p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Cómics</p>
                <p class="stat-value text-indigo-400"><?= count($comics) ?></p>
            </div>
            <div class="glass p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Etiquetas</p>
                <p class="stat-value text-emerald-400"><?= count($allTags) ?></p>
            </div>
            <div class="glass p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Universos</p>
                <p class="stat-value text-amber-400"><?= count($allUniverses) ?></p>
            </div>
            <div class="glass p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Idiomas</p>
                <p class="stat-value text-blue-400"><?= count($allIdiomas) ?></p>
            </div>
            <div class="glass p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Autores</p>
                <p class="stat-value text-purple-400"><?= count($allAutores) ?></p>
            </div>
        </div>

        <!-- Acciones -->
        <div class="flex flex-wrap gap-3 mb-6">
            <a href="?download=1" class="btn-glow text-sm">⬇ Descargar JSON estándar</a>
            <a href="?format=wp-rest&download=1" class="btn-glow text-sm">⬇ Descargar para WP REST API</a>
            <a href="?format=wp-rest" class="btn-ghost text-sm">👁 Vista WP REST</a>
        </div>

        <!-- Vista previa del JSON -->
        <div class="glass p-5">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Vista previa del JSON (primeros <?= min(count($comics), 3) ?> cómics)</h3>
            <pre><?php
                $preview = $payload;
                if (isset($preview['comics']) && count($preview['comics']) > 3) {
                    $preview['comics'] = array_slice($preview['comics'], 0, 3);
                    $preview['_notice'] = 'Mostrando solo 3 de ' . count($payload['comics']) . ' cómics. Descarga el archivo completo.';
                }
                if (isset($preview['posts']) && count($preview['posts']) > 3) {
                    $preview['posts'] = array_slice($preview['posts'], 0, 3);
                    $preview['_notice'] = 'Mostrando solo 3 de ' . count($payload['posts']) . ' posts. Descarga el archivo completo.';
                }
                echo htmlspecialchars(json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            ?></pre>
        </div>

        <!-- Instrucciones -->
        <div class="glass p-5 mt-6">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">📖 Instrucciones para importar en WordPress</h3>
            <ol class="text-sm text-gray-400 space-y-2 list-decimal list-inside">
                <li>Descarga el archivo JSON usando los botones de arriba.</li>
                <li>En WordPress, instala y activa el plugin <strong>WP All Import</strong> o usa la <strong>REST API</strong> directamente.</li>
                <li>Asegúrate de tener las taxonomías creadas en CPT UI:
                    <ul class="list-disc list-inside ml-4 mt-1 text-gray-500 space-y-1">
                        <li><code>etiquetas</code> (non-hierarchical)</li>
                        <li><code>universos</code> (non-hierarchical)</li>
                        <li><code>idiomas</code> (non-hierarchical)</li>
                        <li><code>tipos</code> (non-hierarchical)</li>
                        <li><code>autores</code> (non-hierarchical)</li>
                        <li><code>personajes</code> (non-hierarchical)</li>
                    </ul>
                </li>
                <li>El formato <strong>WP REST API</strong> genera posts con <code>taxonomy_input</code> listo para <code>wp-json/wp/v2/posts</code>.</li>
                <li>Los slugs se generan automáticamente: minúsculas, espacios reemplazados por guiones, sin caracteres especiales.</li>
            </ol>
        </div>
    </div>
</body>
</html>
<?php

} catch (Exception $e) {
    if ($download) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        echo '<html><body style="background:#080b12;color:#f85149;font-family:sans-serif;padding:2rem;">';
        echo '<h1>Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</body></html>';
    }
}

/**
 * Genera un slug limpio para WordPress.
 * Ej: "Pelo Corto" → "pelo-corto", "Oral (Femenino)" → "oral-femenino"
 */
function sanitize_slug(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    // Remover paréntesis y su contenido para slugs (o reemplazar)
    $text = preg_replace('/[()]/u', '', $text);
    // Reemplazar espacios y caracteres no alfanuméricos por guiones
    $text = preg_replace('/[^a-z0-9áéíóúüñ\-.]+/u', '-', $text);
    // Limpiar guiones múltiples y extremos
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-');
    return $text;
}
