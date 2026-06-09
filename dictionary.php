<?php
/**
 * dictionary.php
 *
 * Administrador visual del diccionario de datos:
 * - Visualización y edición de mapeos de etiquetas
 * - Visualización y edición de universos
 * - Propuestas de nuevas etiquetas (desde tags_3hentai.json)
 * - Propuestas de nuevos universos (desde series_3hentai.json)
 *
 * Los datos se guardan en data/custom_mappings.json para no modificar
 * el código fuente de TaxonomyData.php.
 */

require_once __DIR__ . '/includes/TaxonomyData.php';

// ── Config ──
define('CUSTOM_MAPPINGS_FILE', __DIR__ . '/data/custom_mappings.json');
define('TAGS_HARVEST_FILE', __DIR__ . '/tags_3hentai.json');
define('SERIES_HARVEST_FILE', __DIR__ . '/series_3hentai.json');

// ── Cargar mappings personalizados ──
function loadCustomMappings(): array {
    if (!file_exists(CUSTOM_MAPPINGS_FILE)) {
        return ['tags' => [], 'universes' => []];
    }
    $data = json_decode(file_get_contents(CUSTOM_MAPPINGS_FILE), true);
    if (!is_array($data)) {
        return ['tags' => [], 'universes' => []];
    }
    $data['tags'] = $data['tags'] ?? [];
    $data['universes'] = $data['universes'] ?? [];
    return $data;
}

function saveCustomMappings(array $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents(CUSTOM_MAPPINGS_FILE, $json) !== false;
}

// ── Cargar datos de harvest ──
function loadHarvestData(string $file): array {
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

// ── Obtener tags existentes (hardcoded + custom) ──
function getMergedTags(): array {
    $hardcoded = TaxonomyData::getTags();
    $custom = loadCustomMappings();
    // Tags existentes (los que están en la lista de $tags)
    $tags = [];
    foreach ($hardcoded as $tag) {
        $tags[TaxonomyData::normalizeForSearch($tag)] = $tag;
    }
    return $tags;
}

function getMergedTagMappings(): array {
    $hardcoded = TaxonomyData::getTagMappings();
    $custom = loadCustomMappings();
    $all = $hardcoded;
    foreach ($custom['tags'] as $key => $value) {
        $normalized = TaxonomyData::normalizeForSearch($key);
        $all[$normalized] = $value;
    }
    return $all;
}

function getMergedUniverses(): array {
    $hardcoded = TaxonomyData::getUniverses();
    $custom = loadCustomMappings();
    $all = $hardcoded;
    foreach ($custom['universes'] as $univ) {
        $normalized = TaxonomyData::normalizeForSearch($univ);
        // Evitar duplicados
        $exists = false;
        foreach ($all as $existing) {
            if (TaxonomyData::normalizeForSearch($existing) === $normalized) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $all[] = $univ;
        }
    }
    return $all;
}

// ── AJAX handlers ──
$ajaxResponse = null;
$ajaxError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $custom = loadCustomMappings();
        $action = $_POST['action'];
        
        switch ($action) {
            // ─── TAG MAPPINGS ───
            case 'add_tag':
                $original = trim($_POST['original'] ?? '');
                $destino  = trim($_POST['destino'] ?? '');
                if ($original === '' || $destino === '') {
                    throw new Exception('Ambos campos son requeridos');
                }
                $custom['tags'][$original] = $destino;
                saveCustomMappings($custom);
                echo json_encode(['success' => true, 'message' => "Mapeo agregado: {$original} → {$destino}"]);
                exit;
            
            case 'delete_tag':
                $original = trim($_POST['original'] ?? '');
                if ($original === '') {
                    throw new Exception('Tag original requerido');
                }
                // Buscar en custom mappings
                $found = false;
                foreach ($custom['tags'] as $key => $value) {
                    if ($key === $original) {
                        unset($custom['tags'][$key]);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    throw new Exception('El tag no está en los mappings personalizados (los hardcoded no se pueden eliminar aquí)');
                }
                saveCustomMappings($custom);
                echo json_encode(['success' => true, 'message' => "Mapeo eliminado: {$original}"]);
                exit;
            
            // ─── UNIVERSES ───
            case 'add_universe':
                $univName = trim($_POST['name'] ?? '');
                if ($univName === '') {
                    throw new Exception('Nombre del universo requerido');
                }
                $normalized = TaxonomyData::normalizeForSearch($univName);
                // Verificar duplicados en hardcoded
                foreach (TaxonomyData::getUniverses() as $existing) {
                    if (TaxonomyData::normalizeForSearch($existing) === $normalized) {
                        throw new Exception("El universo '{$univName}' ya existe en el diccionario base");
                    }
                }
                // Verificar duplicados en custom
                foreach ($custom['universes'] as $existing) {
                    if (TaxonomyData::normalizeForSearch($existing) === $normalized) {
                        throw new Exception("El universo '{$univName}' ya está en los mappings personalizados");
                    }
                }
                $custom['universes'][] = $univName;
                saveCustomMappings($custom);
                echo json_encode(['success' => true, 'message' => "Universo agregado: {$univName}"]);
                exit;
            
            case 'delete_universe':
                $univName = trim($_POST['name'] ?? '');
                if ($univName === '') {
                    throw new Exception('Nombre del universo requerido');
                }
                $normalized = TaxonomyData::normalizeForSearch($univName);
                $found = false;
                foreach ($custom['universes'] as $i => $existing) {
                    if (TaxonomyData::normalizeForSearch($existing) === $normalized) {
                        array_splice($custom['universes'], $i, 1);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    throw new Exception('El universo no está en los mappings personalizados (los hardcoded no se pueden eliminar aquí)');
                }
                saveCustomMappings($custom);
                echo json_encode(['success' => true, 'message' => "Universo eliminado: {$univName}"]);
                exit;
            
            // ─── SYNC: add from proposals ───
            case 'add_tag_from_proposal':
                $original = trim($_POST['original'] ?? '');
                $destino  = trim($_POST['destino'] ?? $original);
                if ($original === '') {
                    throw new Exception('Tag original requerido');
                }
                $custom['tags'][$original] = $destino;
                saveCustomMappings($custom);
                echo json_encode(['success' => true, 'message' => "Tag agregado al diccionario: {$original} → {$destino}"]);
                exit;
            
            case 'add_universe_from_proposal':
                $univName = trim($_POST['name'] ?? '');
                if ($univName === '') {
                    throw new Exception('Nombre del universo requerido');
                }
                $normalized = TaxonomyData::normalizeForSearch($univName);
                // Verificar duplicados
                foreach (getMergedUniverses() as $existing) {
                    if (TaxonomyData::normalizeForSearch($existing) === $normalized) {
                        throw new Exception("El universo '{$univName}' ya existe");
                    }
                }
                $custom['universes'][] = $univName;
                saveCustomMappings($custom);
                echo json_encode(['success' => true, 'message' => "Universo agregado al diccionario: {$univName}"]);
                exit;
            
            default:
                throw new Exception('Acción desconocida: ' . $action);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Cargar datos para render (GET) ──
$mergedTagMappings = getMergedTagMappings();
$mergedUniverses   = getMergedUniverses();
$hardcodedTags     = TaxonomyData::getTagMappings();
$hardcodedUniverses = TaxonomyData::getUniverses();
$custom            = loadCustomMappings();

$tagsHarvest  = loadHarvestData(TAGS_HARVEST_FILE);
$seriesHarvest = loadHarvestData(SERIES_HARVEST_FILE);

// Extraer listas de tags/universos existentes (normalizados) para saber qué está ya cubierto
$existingTagNormals = [];
foreach ($mergedTagMappings as $key => $value) {
    $existingTagNormals[$key] = true;
}
// También agregar los tags existentes directos
foreach (getMergedTags() as $norm => $tag) {
    $existingTagNormals[$norm] = true;
}

$existingUniverseNormals = [];
foreach ($mergedUniverses as $univ) {
    $existingUniverseNormals[TaxonomyData::normalizeForSearch($univ)] = $univ;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diccionario de Datos — Comic Scraper Pro</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #080b12;
            color: #e2e8f0;
            min-height: 100vh;
        }
        .bg-app {
            position: fixed; inset: 0; z-index: -1;
            background:
                radial-gradient(ellipse 80% 60% at 50% -20%, rgba(99,102,241,0.12) 0%, transparent 60%),
                radial-gradient(ellipse 40% 50% at 80% 80%, rgba(168,85,247,0.08) 0%, transparent 50%),
                radial-gradient(ellipse 30% 40% at 20% 60%, rgba(59,130,246,0.06) 0%, transparent 50%),
                #080b12;
        }
        .font-mono-custom { font-family: 'JetBrains Mono', 'Cascadia Code', 'Fira Code', 'Consolas', monospace; }
        .glass {
            background: rgba(22, 27, 34, 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(48, 54, 61, 0.5);
            border-radius: 1rem;
            transition: all 0.25s ease;
        }
        .glass-strong {
            background: rgba(13, 17, 23, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(48, 54, 61, 0.6);
            border-radius: 1rem;
        }
        .tab-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
            background: transparent;
            color: #94a3b8;
        }
        .tab-btn:hover { background: rgba(99,102,241,0.1); color: #e2e8f0; }
        .tab-btn.active {
            background: rgba(99,102,241,0.15);
            border-color: rgba(99,102,241,0.3);
            color: #a5b4fc;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .badge-source {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.025em;
        }
        .badge-base { background: rgba(99,102,241,0.2); color: #a5b4fc; }
        .badge-custom { background: rgba(34,197,94,0.2); color: #4ade80; }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(48, 54, 61, 0.6);
            border-radius: 0.75rem;
            color: #e2e8f0;
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .search-input:focus { border-color: rgba(99,102,241,0.5); }

        .btn-primary {
            padding: 0.5rem 1.25rem;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            border: none;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.3); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-secondary {
            padding: 0.5rem 1.25rem;
            background: rgba(30, 41, 59, 0.6);
            color: #94a3b8;
            border: 1px solid rgba(48, 54, 61, 0.6);
            border-radius: 0.75rem;
            font-weight: 500;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-secondary:hover { background: rgba(30, 41, 59, 0.8); color: #e2e8f0; }
        .btn-danger {
            padding: 0.5rem 1.25rem;
            background: rgba(239,68,68,0.15);
            color: #fca5a5;
            border: 1px solid rgba(239,68,68,0.3);
            border-radius: 0.75rem;
            font-weight: 500;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-danger:hover { background: rgba(239,68,68,0.25); }

        .toast {
            position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 100;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 500;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast-success { background: rgba(34,197,94,0.2); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
        .toast-error { background: rgba(239,68,68,0.2); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }

        .stat-card {
            padding: 1.25rem;
            text-align: center;
        }
        .stat-value { font-size: 2rem; font-weight: 800; color: #a5b4fc; }
        .stat-label { font-size: 0.8rem; color: #64748b; margin-top: 0.25rem; }

        .proposal-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(48, 54, 61, 0.3);
            transition: background 0.15s;
        }
        .proposal-row:hover { background: rgba(99,102,241,0.05); }
        .proposal-row:last-child { border-bottom: none; }

        .table-wrap {
            overflow-x: auto;
        }
        table.dict-table { width: 100%; border-collapse: collapse; }
        table.dict-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid rgba(48, 54, 61, 0.5);
        }
        table.dict-table td {
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            border-bottom: 1px solid rgba(48, 54, 61, 0.2);
        }
        table.dict-table tr:last-child td { border-bottom: none; }
        table.dict-table tr:hover td { background: rgba(99,102,241,0.04); }

        .action-cell { white-space: nowrap; text-align: right; }
    </style>
</head>
<body>
<div class="bg-app"></div>

<div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Header -->
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-white flex items-center gap-3">
                <span>📖</span> Administrador del Diccionario de Datos
            </h1>
            <p class="text-sm text-slate-500 mt-1">
                Gestiona mapeos de etiquetas y universos. Los cambios se guardan en 
                <code class="font-mono-custom text-xs px-1.5 py-0.5 rounded bg-slate-800 text-slate-400">data/custom_mappings.json</code>
            </p>
        </div>
        <a href="index.php" class="btn-secondary text-sm flex items-center gap-2">
            ← Volver al Scraper
        </a>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="glass stat-card">
            <div class="stat-value"><?= count($mergedTagMappings) ?></div>
            <div class="stat-label">Tags mapeados</div>
        </div>
        <div class="glass stat-card">
            <div class="stat-value"><?= count($mergedUniverses) ?></div>
            <div class="stat-label">Universos registrados</div>
        </div>
        <div class="glass stat-card">
            <div class="stat-value"><?= count($custom['tags']) ?></div>
            <div class="stat-label">Mapeos personalizados</div>
        </div>
        <div class="glass stat-card">
            <div class="stat-value"><?= count($custom['universes']) ?></div>
            <div class="stat-label">Universos personalizados</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="glass-strong mb-6">
        <div class="flex flex-wrap gap-2 p-2 border-b border-slate-700/50">
            <button class="tab-btn active" data-tab="tab-tags">📑 Tags</button>
            <button class="tab-btn" data-tab="tab-universes">🌌 Universos</button>
            <button class="tab-btn" data-tab="tab-proposals-tags">📋 Propuestas Tags</button>
            <button class="tab-btn" data-tab="tab-proposals-series">🌠 Propuestas Universos</button>
        </div>

        <!-- ════════════ TAB: TAGS ════════════ -->
        <div id="tab-tags" class="tab-content active p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-white">Mapeos de Etiquetas</h2>
                <button onclick="toggleForm('addTagForm')" class="btn-primary text-sm">+ Nuevo Mapeo</button>
            </div>

            <!-- Add form -->
            <div id="addTagForm" class="hidden mb-4 p-4 rounded-lg bg-slate-800/50 border border-slate-700/50">
                <form onsubmit="return submitForm('add_tag', this)">
                    <input type="hidden" name="action" value="add_tag">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Tag original (3hentai)</label>
                            <input type="text" name="original" required
                                   class="search-input" placeholder="ej: big breasts">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Tag destino (WordPress)</label>
                            <input type="text" name="destino" required
                                   class="search-input" placeholder="ej: tetona">
                        </div>
                        <div class="flex items-end gap-2">
                            <button type="submit" class="btn-primary">Guardar</button>
                            <button type="button" onclick="toggleForm('addTagForm')" class="btn-secondary">Cancelar</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Search -->
            <div class="mb-4">
                <input type="text" id="searchTags" class="search-input" placeholder="Buscar en mapeos de tags..." oninput="filterTable('searchTags', 'tagsTable')">
            </div>

            <!-- Table -->
            <div class="table-wrap">
                <table class="dict-table" id="tagsTable">
                    <thead>
                        <tr>
                            <th>Tag Original</th>
                            <th>→ Tag Destino</th>
                            <th>Origen</th>
                            <th class="action-cell">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hardcodedTags as $original => $destino): 
                            $normOrig = TaxonomyData::normalizeForSearch($original);
                            // Saltar si está sobrescrito por custom
                            if (isset($custom['tags'][$original])) continue;
                        ?>
                        <tr>
                            <td class="font-mono-custom text-sm"><?= htmlspecialchars($original) ?></td>
                            <td><span class="text-emerald-400"><?= htmlspecialchars($destino) ?></span></td>
                            <td><span class="badge-source badge-base">Base</span></td>
                            <td class="action-cell">
                                <span class="text-xs text-slate-600">(hardcoded)</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php foreach ($custom['tags'] as $original => $destino): ?>
                        <tr class="bg-emerald-900/10">
                            <td class="font-mono-custom text-sm"><?= htmlspecialchars($original) ?></td>
                            <td><span class="text-emerald-400"><?= htmlspecialchars($destino) ?></span></td>
                            <td><span class="badge-source badge-custom">Custom</span></td>
                            <td class="action-cell">
                                <button onclick="deleteItem('delete_tag', 'original', '<?= htmlspecialchars(addslashes($original)) ?>')"
                                        class="btn-danger text-xs py-1 px-2">Eliminar</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($hardcodedTags) && empty($custom['tags'])): ?>
                        <tr><td colspan="4" class="text-center text-slate-500 py-8">No hay mapeos de tags</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ════════════ TAB: UNIVERSOS ════════════ -->
        <div id="tab-universes" class="tab-content p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-white">Universos Registrados</h2>
                <button onclick="toggleForm('addUnivForm')" class="btn-primary text-sm">+ Nuevo Universo</button>
            </div>

            <div id="addUnivForm" class="hidden mb-4 p-4 rounded-lg bg-slate-800/50 border border-slate-700/50">
                <form onsubmit="return submitForm('add_universe', this)">
                    <input type="hidden" name="action" value="add_universe">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Nombre del universo</label>
                            <input type="text" name="name" required
                                   class="search-input" placeholder="ej: My Hero Academia">
                        </div>
                        <div class="flex items-end gap-2">
                            <button type="submit" class="btn-primary">Guardar</button>
                            <button type="button" onclick="toggleForm('addUnivForm')" class="btn-secondary">Cancelar</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="mb-4">
                <input type="text" id="searchUniverses" class="search-input" placeholder="Buscar universos..." oninput="filterTable('searchUniverses', 'univTable')">
            </div>

            <div class="table-wrap">
                <table class="dict-table" id="univTable">
                    <thead>
                        <tr>
                            <th>Universo</th>
                            <th>Origen</th>
                            <th class="action-cell">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hardcodedUniverses as $univ): 
                            // Saltar si está en custom (para evitar duplicado visual)
                            $normUniv = TaxonomyData::normalizeForSearch($univ);
                            $inCustom = false;
                            foreach ($custom['universes'] as $cu) {
                                if (TaxonomyData::normalizeForSearch($cu) === $normUniv) {
                                    $inCustom = true;
                                    break;
                                }
                            }
                            if ($inCustom) continue;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($univ) ?></td>
                            <td><span class="badge-source badge-base">Base</span></td>
                            <td class="action-cell">
                                <span class="text-xs text-slate-600">(hardcoded)</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php foreach ($custom['universes'] as $univ): ?>
                        <tr class="bg-emerald-900/10">
                            <td><?= htmlspecialchars($univ) ?></td>
                            <td><span class="badge-source badge-custom">Custom</span></td>
                            <td class="action-cell">
                                <button onclick="deleteItem('delete_universe', 'name', '<?= htmlspecialchars(addslashes($univ)) ?>')"
                                        class="btn-danger text-xs py-1 px-2">Eliminar</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($hardcodedUniverses) && empty($custom['universes'])): ?>
                        <tr><td colspan="3" class="text-center text-slate-500 py-8">No hay universos registrados</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ════════════ TAB: PROPUESTAS TAGS ════════════ -->
        <div id="tab-proposals-tags" class="tab-content p-6">
            <h2 class="text-lg font-semibold text-white mb-2">Propuestas de Etiquetas (desde 3hentai.net)</h2>
            <p class="text-sm text-slate-500 mb-4">
                Etiquetas de 3hentai.net que aún no tienen mapeo en el diccionario.
                Ordenadas por cantidad de resultados (popularidad).
            </p>

            <div class="mb-4">
                <input type="text" id="searchPropTags" class="search-input" placeholder="Buscar en propuestas de tags..." oninput="filterProposals('searchPropTags', 'propTagsContainer')">
            </div>

            <?php
            // Extraer propuestas de tags NO mapeadas
            $proposalTags = [];
            if (isset($tagsHarvest['series']) && is_array($tagsHarvest['series'])) {
                foreach ($tagsHarvest['series'] as $tag) {
                    $displayName = $tag['display_name'] ?? ($tag['name'] ?? '');
                    $normName = TaxonomyData::normalizeForSearch($displayName);
                    // Verificar si ya está mapeado
                    if (isset($existingTagNormals[$normName])) continue;
                    // Verificar si ya está en custom
                    if (isset($custom['tags'][$displayName])) continue;
                    
                    $proposalTags[] = [
                        'name' => $displayName,
                        'count' => $tag['count'] ?? 0,
                        'count_raw' => $tag['count_raw'] ?? '0',
                        'status' => $tag['status'] ?? 'SIN MAPEO',
                    ];
                }
            }
            // Ordenar por count descendente
            usort($proposalTags, fn($a, $b) => $b['count'] - $a['count']);
            ?>

            <div id="propTagsContainer">
                <?php if (empty($proposalTags)): ?>
                <div class="text-center text-slate-500 py-8 glass">
                    🎉 Todas las etiquetas de 3hentai.net están cubiertas
                </div>
                <?php else: ?>
                <div class="glass overflow-hidden">
                    <?php foreach ($proposalTags as $i => $pt): ?>
                    <div class="proposal-row" data-search="<?= htmlspecialchars(mb_strtolower($pt['name'], 'UTF-8')) ?>">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <span class="text-xs text-slate-600 font-mono-custom w-16 text-right shrink-0">
                                <?= htmlspecialchars($pt['count_raw']) ?>
                            </span>
                            <span class="text-sm font-medium truncate"><?= htmlspecialchars($pt['name']) ?></span>
                            <?php if ($pt['status'] !== 'SIN MAPEO'): ?>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-900/30 text-yellow-400 border border-yellow-700/30">
                                <?= htmlspecialchars($pt['status']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 shrink-0 ml-4">
                            <button onclick="addFromProposal(
                                'add_tag_from_proposal',
                                '<?= htmlspecialchars(addslashes($pt['name'])) ?>',
                                '<?= htmlspecialchars(addslashes($pt['name'])) ?>',
                                this
                            )" class="btn-primary text-xs py-1.5 px-3 whitespace-nowrap">
                                + Mapear
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════ TAB: PROPUESTAS UNIVERSOS ════════════ -->
        <div id="tab-proposals-series" class="tab-content p-6">
            <h2 class="text-lg font-semibold text-white mb-2">Propuestas de Universos (desde 3hentai.net)</h2>
            <p class="text-sm text-slate-500 mb-4">
                Series/universos de 3hentai.net que aún no están en el diccionario.
                Ordenadas por cantidad de resultados (popularidad).
            </p>

            <div class="mb-4">
                <input type="text" id="searchPropSeries" class="search-input" placeholder="Buscar en propuestas de universos..." oninput="filterProposals('searchPropSeries', 'propSeriesContainer')">
            </div>

            <?php
            // Extraer propuestas de universos NO mapeados
            $proposalSeries = [];
            if (isset($seriesHarvest['series']) && is_array($seriesHarvest['series'])) {
                foreach ($seriesHarvest['series'] as $s) {
                    $displayName = $s['display_name'] ?? '';
                    if ($displayName === '') continue;
                    $normName = TaxonomyData::normalizeForSearch($displayName);
                    // Saltar si ya existe
                    if (isset($existingUniverseNormals[$normName])) continue;
                    // Saltar si cualquiera de los alias ya existe
                    $aliases = preg_split('/\s*\|\s*/', mb_strtolower($displayName, 'UTF-8'));
                    $alreadyExists = false;
                    foreach ($aliases as $alias) {
                        $aliasNorm = TaxonomyData::normalizeForSearch($alias);
                        if (isset($existingUniverseNormals[$aliasNorm])) {
                            $alreadyExists = true;
                            break;
                        }
                    }
                    if ($alreadyExists) continue;
                    
                    $proposalSeries[] = [
                        'name' => $displayName,
                        'count' => $s['count'] ?? 0,
                        'count_raw' => $s['count_raw'] ?? '0',
                        'status' => $s['status'] ?? 'SIN COINCIDENCIA',
                    ];
                }
            }
            usort($proposalSeries, fn($a, $b) => $b['count'] - $a['count']);
            ?>

            <div id="propSeriesContainer">
                <?php if (empty($proposalSeries)): ?>
                <div class="text-center text-slate-500 py-8 glass">
                    🎉 Todas las series de 3hentai.net están cubiertas
                </div>
                <?php else: ?>
                <div class="glass overflow-hidden">
                    <?php foreach ($proposalSeries as $ps): ?>
                    <div class="proposal-row" data-search="<?= htmlspecialchars(mb_strtolower($ps['name'], 'UTF-8')) ?>">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <span class="text-xs text-slate-600 font-mono-custom w-16 text-right shrink-0">
                                <?= htmlspecialchars($ps['count_raw']) ?>
                            </span>
                            <span class="text-sm font-medium truncate"><?= htmlspecialchars($ps['name']) ?></span>
                            <?php if ($ps['status'] !== 'SIN COINCIDENCIA'): ?>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-900/30 text-yellow-400 border border-yellow-700/30">
                                <?= htmlspecialchars($ps['status']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 shrink-0 ml-4">
                            <button onclick="addFromProposal(
                                'add_universe_from_proposal',
                                '<?= htmlspecialchars(addslashes($ps['name'])) ?>',
                                '<?= htmlspecialchars(addslashes($ps['name'])) ?>',
                                this
                            )" class="btn-primary text-xs py-1.5 px-3 whitespace-nowrap">
                                + Agregar
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="toast"></div>

<script>
// ── Tab switching ──
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

// ── Toggle form visibility ──
function toggleForm(id) {
    const el = document.getElementById(id);
    el.classList.toggle('hidden');
}

// ── Toast notification ──
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast toast-' + type + ' show';
    setTimeout(() => toast.classList.remove('show'), 3500);
}

// ── Submit form via AJAX ──
async function submitForm(action, form) {
    event.preventDefault();
    const formData = new FormData(form);
    formData.set('action', action);
    
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Guardando...';
    
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.message || 'Error', 'error');
            btn.disabled = false;
            btn.textContent = originalText;
        }
    } catch (err) {
        showToast('Error de conexión: ' + err.message, 'error');
        btn.disabled = false;
        btn.textContent = originalText;
    }
    return false;
}

// ── Delete item ──
async function deleteItem(action, fieldName, value) {
    if (!confirm('¿Estás seguro de eliminar esta entrada?')) return;
    
    const formData = new FormData();
    formData.set('action', action);
    formData.set(fieldName, value);
    
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.message || 'Error', 'error');
        }
    } catch (err) {
        showToast('Error de conexión: ' + err.message, 'error');
    }
}

// ── Add from proposal ──
async function addFromProposal(action, original, destino, btn) {
    const formData = new FormData();
    formData.set('action', action);
    formData.set('original', original);
    formData.set('destino', destino);
    formData.set('name', original);
    
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Agregando...';
    
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            // Mark row as added
            const row = btn.closest('.proposal-row');
            row.style.opacity = '0.4';
            btn.textContent = '✓ Agregado';
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-secondary');
        } else {
            showToast(data.message || 'Error', 'error');
            btn.disabled = false;
            btn.textContent = originalText;
        }
    } catch (err) {
        showToast('Error de conexión: ' + err.message, 'error');
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

// ── Filter table ──
function filterTable(inputId, tableId) {
    const query = document.getElementById(inputId).value.toLowerCase();
    const rows = document.getElementById(tableId).querySelectorAll('tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    });
}

// ── Filter proposals ──
function filterProposals(inputId, containerId) {
    const query = document.getElementById(inputId).value.toLowerCase();
    const rows = document.getElementById(containerId).querySelectorAll('.proposal-row');
    rows.forEach(row => {
        const text = row.dataset.search || row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    });
}
</script>

</body>
</html>
