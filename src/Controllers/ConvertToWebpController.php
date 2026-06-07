<?php
/**
 * src/Controllers/ConvertToWebpController.php
 *
 * Controller for converting downloaded comic images to WebP format.
 * Supports web mode (JSON responses) and CLI mode.
 *
 * Web params:
 *   action=convert_all       → Convert all comics
 *   action=convert&id=ID     → Convert single comic
 *   quality=85               → WebP quality (optional)
 *
 * CLI usage:
 *   php convert_to_webp.php [--all|--id=ID] [--quality=85]
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class ConvertToWebpController extends BaseController
{
    /**
     * Handle the conversion request.
     * Dispatches to CLI or web mode automatically.
     */
    public function handle(): void
    {
        if ($this->isCli()) {
            $this->handleCli();
        } else {
            $this->handleWeb();
        }
    }

    /**
     * CLI mode handler.
     */
    private function handleCli(): void
    {
        // Verify downloads directory write permissions
        $testDir = __DIR__ . '/../../descargas';
        if (is_dir($testDir) && !is_writable($testDir)) {
            echo "⚠️  ADVERTENCIA: El directorio 'descargas/' no tiene permisos de escritura.\n";
            echo "   Los cómics fueron descargados por el servidor web (usuario: daemon).\n";
            echo "   Para ejecutar este script desde CLI, necesitas permisos de escritura.\n";
            echo "   Solución: sudo chmod -R o+w descargas/  (o ejecuta via web)\n\n";
        }

        global $argv;
        $options = getopt('', ['all', 'id:', 'quality:']);
        $quality = isset($options['quality']) ? (int) $options['quality'] : 85;
        $quality = max(1, min(100, $quality));

        if (isset($options['all'])) {
            $this->convertAll($quality, false);
        } elseif (isset($options['id'])) {
            $this->convertById((int) $options['id'], $quality, false);
        } else {
            echo "Uso: php convert_to_webp.php [--all|--id=ID] [--quality=85]\n";
            echo "  --all          Convierte TODOS los cómics descargados\n";
            echo "  --id=ID        Convierte un cómic específico por ID\n";
            echo "  --quality=85   Calidad WebP (1-100, default 85)\n";
            exit(1);
        }
    }

    /**
     * Web mode handler.
     */
    private function handleWeb(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $action  = trim($this->param('action', ''));
        $id      = (int) $this->param('id', 0);
        $quality = max(1, min(100, (int) $this->param('quality', 85)));

        if ($action === 'convert_all') {
            $result = $this->convertAll($quality, true);
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
        } elseif ($action === 'convert' && $id > 0) {
            $result = $this->convertById($id, $quality, true);
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Use action=convert_all o action=convert&id=ID',
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /**
     * Convert ALL comics in the database to WebP.
     */
    private function convertAll(int $quality, bool $isWeb): array
    {
        $pdo = $this->getPDO();

        $stmt = $pdo->query(
            'SELECT id_fuente, titulo, ruta_carpeta FROM comics_descargados WHERE ruta_carpeta IS NOT NULL'
        );
        $comics = $stmt->fetchAll();

        if (empty($comics)) {
            $msg = "No hay cómics descargados para convertir.";
            if ($isWeb) return ['message' => $msg, 'total' => 0, 'convertidos' => 0, 'errores' => 0];
            echo $msg . "\n";
            return ['total' => 0, 'convertidos' => 0, 'errores' => 0];
        }

        $total       = count($comics);
        $convertidos = 0;
        $errores     = 0;
        $totalBytesOriginal = 0;
        $totalBytesWebp     = 0;

        foreach ($comics as $comic) {
            echo "🔄 [{$comic['id_fuente']}] {$comic['titulo']}... ";

            if (!$comic['ruta_carpeta'] || !is_dir($comic['ruta_carpeta'])) {
                echo "⚠️  Carpeta no encontrada\n";
                $errores++;
                continue;
            }

            $stats = \convertir_comic_a_webp($comic['ruta_carpeta'], $quality);

            if ($stats['converted'] > 0) {
                $ahorro = $stats['bytes_ahorrados'];
                $ahorroFmt = $this->formatBytes($ahorro);
                echo "✅ {$stats['converted']} imágenes convertidas (-{$ahorroFmt})\n";
                $convertidos++;
                $totalBytesOriginal += $stats['bytes_original'];
                $totalBytesWebp     += $stats['bytes_webp'];

                $nuevoTamano = \calcular_tamano_dir($comic['ruta_carpeta']);
                $stmtUpd = $pdo->prepare(
                    'UPDATE comics_descargados SET tamano_bytes = ? WHERE id_fuente = ?'
                );
                $stmtUpd->execute([$nuevoTamano, $comic['id_fuente']]);
            } elseif ($stats['skipped'] > 0) {
                echo "⏭️  Ya en WebP ({$stats['skipped']} imágenes)\n";
                $convertidos++;
            } else {
                echo "⚠️  Sin imágenes para convertir\n";
            }

            if ($stats['failed'] > 0) {
                echo "     ⚠️  {$stats['failed']} fallos de conversión\n";
            }
        }

        $totalAhorro = $totalBytesOriginal - $totalBytesWebp;
        $resumen = "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "🎉 CONVERSIÓN COMPLETADA\n"
                 . "  • Total cómics procesados: {$total}\n"
                 . "  • Convertidos/OK: {$convertidos}\n"
                 . "  • Errores: {$errores}\n"
                 . "  • Ahorro total: " . $this->formatBytes($totalAhorro) . "\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        if ($isWeb) {
            return [
                'message' => $resumen,
                'total' => $total,
                'convertidos' => $convertidos,
                'errores' => $errores,
                'bytes_ahorrados' => $totalAhorro,
            ];
        }

        echo $resumen;
        return ['total' => $total, 'convertidos' => $convertidos, 'errores' => $errores];
    }

    /**
     * Convert a single comic by ID to WebP.
     */
    private function convertById(int $id, int $quality, bool $isWeb): array
    {
        $pdo = $this->getPDO();

        $stmt = $pdo->prepare(
            'SELECT id_fuente, titulo, ruta_carpeta FROM comics_descargados WHERE id_fuente = ?'
        );
        $stmt->execute([$id]);
        $comic = $stmt->fetch();

        if (!$comic) {
            $msg = "Cómic ID {$id} no encontrado en la base de datos.";
            if ($isWeb) return ['message' => $msg];
            echo $msg . "\n";
            return ['message' => $msg];
        }

        if (!$comic['ruta_carpeta'] || !is_dir($comic['ruta_carpeta'])) {
            $msg = "Carpeta del cómic ID {$id} no encontrada en disco.";
            if ($isWeb) return ['message' => $msg];
            echo $msg . "\n";
            return ['message' => $msg];
        }

        $stats = \convertir_comic_a_webp($comic['ruta_carpeta'], $quality);

        // Update size in DB
        $nuevoTamano = \calcular_tamano_dir($comic['ruta_carpeta']);
        $stmtUpd = $pdo->prepare(
            'UPDATE comics_descargados SET tamano_bytes = ? WHERE id_fuente = ?'
        );
        $stmtUpd->execute([$nuevoTamano, $id]);

        if ($isWeb) {
            return [
                'message' => "Cómic «{$comic['titulo']}» (ID {$id}): {$stats['converted']} convertidas, {$stats['skipped']} ya webp, {$stats['failed']} fallos. Ahorrado: " . $this->formatBytes($stats['bytes_ahorrados']),
                'id' => $id,
                'titulo' => $comic['titulo'],
                'stats' => $stats,
            ];
        }

        echo "📊 Resultados para «{$comic['titulo']}» (ID {$id}):\n";
        echo "  • Convertidas: {$stats['converted']}\n";
        echo "  • Ya en WebP:  {$stats['skipped']}\n";
        echo "  • Fallos:      {$stats['failed']}\n";
        echo "  • Ahorrado:    " . $this->formatBytes($stats['bytes_ahorrados']) . "\n";

        return ['message' => 'OK', 'stats' => $stats];
    }
}
