<?php
/**
 * reprocess_taxonomies.php
 *
 * Re-procesa las taxonomías de todos los cómics existentes aplicando
 * las nuevas reglas:
 *
 *   1. Tipo forzado a "manga" (todo proviene de 3hentai.net)
 *   2. Etiquetas re-mapeadas usando el diccionario actualizado (TaxonomyData)
 *   3. Tags sin equivalencia IGNORADOS (no se incluyen)
 *   4. Autores extraídos del campo taxonomias.autores existente
 *
 * USO:
 *   php reprocess_taxonomies.php              → Modo preview (solo muestra cambios)
 *   php reprocess_taxonomies.php --apply      → Aplica los cambios a la BD
 *   php reprocess_taxonomies.php --apply --id=12345  → Solo un cómic específico
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/includes/TaxonomyProcessor.php';

$isApply  = in_array('--apply', $argv ?? []);
$specificId = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $specificId = (int) $m[1];
    }
}

echo "=== Reprocesador de Taxonomías ===\n";
echo "Modo: " . ($isApply ? "APLICAR cambios" : "PREVIEW (solo lectura)") . "\n";
if ($specificId) {
    echo "Cómic específico: ID {$specificId}\n";
}
echo "\n";

// ── Instanciar procesador ──
$taxProcessor = new TaxonomyProcessor();

// ── Consultar cómics ──
$sql = "SELECT id_fuente, titulo, taxonomias, tags, autor, artista
        FROM comics_descargados
        WHERE taxonomias IS NOT NULL";
if ($specificId) {
    $sql .= " AND id_fuente = :id";
}
$sql .= " ORDER BY id_fuente ASC";

$stmt = $pdo->prepare($sql);
if ($specificId) {
    $stmt->execute([':id' => $specificId]);
} else {
    $stmt->execute();
}
$comics = $stmt->fetchAll();

echo "Total cómics a procesar: " . count($comics) . "\n\n";

$updated   = 0;
$skipped   = 0;
$errors    = 0;

foreach ($comics as $comic) {
    $id   = $comic['id_fuente'];
    $titulo = $comic['titulo'];

    echo "[{$id}] {$titulo}\n";

    $taxonomias = json_decode($comic['taxonomias'], true);
    if (!is_array($taxonomias)) {
        echo "  ⚠️  JSON inválido, saltando...\n";
        $skipped++;
        continue;
    }

    // ── 1. Forzar tipo a "manga" ──
    $oldTipos = $taxonomias['tipos'] ?? [];
    $taxonomias['tipos'] = ['manga'];

    // ── 2. Re-procesar etiquetas con el TagProcessor actualizado ──
    // Usamos el texto original de tags (columna `tags`) si existe,
    // o las etiquetas viejas como fallback.
    $rawTags = $comic['tags'] ?? null;
    if ($rawTags && trim($rawTags) !== '') {
        $newTags = $taxProcessor->getTagProcessor()->process($rawTags);
    } else {
        // Fallback: usar las etiquetas viejas del JSON
        $oldTags = $taxonomias['etiquetas'] ?? [];
        $newTags = $taxProcessor->getTagProcessor()->process(implode(', ', $oldTags));
    }
    $oldTagsList = $taxonomias['etiquetas'] ?? [];
    $taxonomias['etiquetas'] = $newTags;

    // ── 3. Asegurar autores desde los datos existentes ──
    // Si ya hay autores en taxonomias, mantenerlos
    // (vienen del scraping de "Artistas:")
    if (empty($taxonomias['autores'])) {
        // Intentar desde columna autor o artista
        $autorFallback = $comic['autor'] ?? $comic['artista'] ?? null;
        if ($autorFallback) {
            $mapped = $taxProcessor->process(['autor' => $autorFallback]);
            $taxonomias['autores'] = $mapped['autores'] ?? [];
        }
    }

    // ── Mostrar diff ──
    $changedTipos = $oldTipos !== $taxonomias['tipos'];
    $removedTags  = array_diff($oldTagsList, $newTags);
    $addedTags    = array_diff($newTags, $oldTagsList);
    $changed      = $changedTipos || !empty($removedTags) || !empty($addedTags);

    if ($changedTipos) {
        echo "  📁 Tipo: " . implode(', ', $oldTipos) . " → manga\n";
    }
    if (!empty($removedTags)) {
        echo "  🗑️  Tags eliminados (sin equivalencia): " . implode(', ', $removedTags) . "\n";
    }
    if (!empty($addedTags)) {
        echo "  ✨ Tags añadidos/mapeados: " . implode(', ', $addedTags) . "\n";
    }
    if (!$changed) {
        echo "  ✓ Sin cambios\n";
    }

    // ── Aplicar cambios ──
    if ($isApply && $changed) {
        $jsonUpdated = json_encode($taxonomias, JSON_UNESCAPED_UNICODE);
        try {
            $upd = $pdo->prepare(
                'UPDATE comics_descargados SET taxonomias = :tax WHERE id_fuente = :id'
            );
            $upd->execute([
                ':tax'  => $jsonUpdated,
                ':id'   => $id,
            ]);
            echo "  ✅ Actualizado\n";
            $updated++;
        } catch (Exception $e) {
            echo "  ❌ Error: " . $e->getMessage() . "\n";
            $errors++;
        }
    } elseif ($isApply) {
        // Sin cambios, pero forzamos tipo manga aunque el resto sea igual
        if ($changedTipos) {
            $jsonUpdated = json_encode($taxonomias, JSON_UNESCAPED_UNICODE);
            $upd = $pdo->prepare(
                'UPDATE comics_descargados SET taxonomias = :tax WHERE id_fuente = :id'
            );
            $upd->execute([':tax' => $jsonUpdated, ':id' => $id]);
            echo "  ✅ Tipo actualizado a manga\n";
            $updated++;
        } else {
            $skipped++;
        }
    } else {
        if ($changed) $updated++; // contamos como "cambiaria" en preview
        else $skipped++;
    }

    echo "\n";
}

// ── Resumen ──
echo "═══════════════════════════════════════\n";
echo "RESUMEN:\n";
if ($isApply) {
    echo "  Actualizados: {$updated}\n";
    echo "  Sin cambios:  {$skipped}\n";
    echo "  Errores:      {$errors}\n";
} else {
    echo "  Cómics que cambiarían: {$updated}\n";
    echo "  Cómics sin cambios:    {$skipped}\n";
    echo "  Errores:               {$errors}\n";
    echo "\n";
    echo "Para APLICAR los cambios, ejecute:\n";
    echo "  php reprocess_taxonomies.php --apply\n";
}
echo "\n";
