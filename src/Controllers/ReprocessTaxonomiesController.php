<?php
/**
 * src/Controllers/ReprocessTaxonomiesController.php
 *
 * Controller for re-processing taxonomies of all existing comics,
 * applying updated rules:
 *   1. Type forced to "manga"
 *   2. Tags re-mapped using current dictionary
 *   3. Tags without equivalence are IGNORED
 *   4. Authors extracted from existing taxonomias.autores
 *
 * CLI usage:
 *   php reprocess_taxonomies.php                → Preview mode
 *   php reprocess_taxonomies.php --apply        → Apply changes to DB
 *   php reprocess_taxonomies.php --apply --id=12345 → Single comic
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class ReprocessTaxonomiesController extends BaseController
{
    /**
     * Handle reprocessing request.
     * Only runs in CLI mode.
     */
    public function handle(): void
    {
        if (!$this->isCli()) {
            $this->json([
                'success' => false,
                'message' => 'Este comando solo se ejecuta desde CLI: php reprocess_taxonomies.php [--apply] [--id=N]',
            ], 400);
        }

        global $argv;
        $isApply    = in_array('--apply', $argv ?? []);
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

        // Require TaxonomyProcessor
        $taxProcessor = $this->loadTaxonomyProcessor();

        $pdo = $this->getPDO();

        // ── Query comics ──
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

        $updated = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($comics as $comic) {
            $id     = $comic['id_fuente'];
            $titulo = $comic['titulo'];

            echo "[{$id}] {$titulo}\n";

            $taxonomias = json_decode($comic['taxonomias'], true);
            if (!is_array($taxonomias)) {
                echo "  ⚠️  JSON inválido, saltando...\n";
                $skipped++;
                continue;
            }

            // ── 1. Force type to "manga" ──
            $oldTipos = $taxonomias['tipos'] ?? [];
            $taxonomias['tipos'] = ['manga'];

            // ── 2. Re-process tags ──
            $rawTags = $comic['tags'] ?? null;
            if ($rawTags && trim($rawTags) !== '') {
                $newTags = $taxProcessor->getTagProcessor()->process($rawTags);
            } else {
                $oldTags = $taxonomias['etiquetas'] ?? [];
                $newTags = $taxProcessor->getTagProcessor()->process(implode(', ', $oldTags));
            }
            $oldTagsList = $taxonomias['etiquetas'] ?? [];
            $taxonomias['etiquetas'] = $newTags;

            // ── 3. Ensure authors from existing data ──
            if (empty($taxonomias['autores'])) {
                $autorFallback = $comic['autor'] ?? $comic['artista'] ?? null;
                if ($autorFallback) {
                    $mapped = $taxProcessor->process(['autor' => $autorFallback]);
                    $taxonomias['autores'] = $mapped['autores'] ?? [];
                }
            }

            // ── Show diff ──
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

            // ── Apply changes ──
            if ($isApply && $changed) {
                $jsonUpdated = json_encode($taxonomias, JSON_UNESCAPED_UNICODE);
                try {
                    $upd = $pdo->prepare(
                        'UPDATE comics_descargados SET taxonomias = :tax WHERE id_fuente = :id'
                    );
                    $upd->execute([':tax' => $jsonUpdated, ':id' => $id]);
                    echo "  ✅ Actualizado\n";
                    $updated++;
                } catch (\Exception $e) {
                    echo "  ❌ Error: " . $e->getMessage() . "\n";
                    $errors++;
                }
            } elseif ($isApply) {
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
                if ($changed) $updated++;
                else $skipped++;
            }

            echo "\n";
        }

        // ── Summary ──
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
    }

    /**
     * Load the TaxonomyProcessor class.
     */
    private function loadTaxonomyProcessor(): \TaxonomyProcessor
    {
        require_once __DIR__ . '/../../includes/TaxonomyProcessor.php';
        return new \TaxonomyProcessor();
    }
}
