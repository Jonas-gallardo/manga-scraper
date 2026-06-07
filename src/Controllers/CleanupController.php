<?php
/**
 * src/Controllers/CleanupController.php
 *
 * Controller for the complete system cleanup utility.
 * Drops all tables, deletes downloads, logs, and resets config.
 * Requires ?confirm=yes to execute.
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class CleanupController extends BaseController
{
    /**
     * Handle cleanup requests.
     * GET ?confirm=yes → Execute cleanup
     * GET (no confirm) → Show confirmation page
     */
    public function handle(): void
    {
        if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
            $this->executeCleanup();
        } else {
            $this->showConfirmation();
        }
    }

    /**
     * Show the cleanup confirmation page.
     */
    private function showConfirmation(): void
    {
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>🧹 Limpieza Completa — Comic Scraper Pro</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                body { background: #0d1117; color: #e0e0e0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, -apple-system, sans-serif; }
                .card { background: #161b22; border: 1px solid #30363d; border-radius: 1rem; padding: 2.5rem; max-width: 560px; width: 100%; text-align: center; }
                .danger-zone { background: rgba(248, 81, 73, 0.08); border: 1px solid rgba(248, 81, 73, 0.25); border-radius: 0.75rem; padding: 1.25rem; margin: 1.5rem 0; }
                .btn-danger { padding: 0.75rem 2rem; border-radius: 0.5rem; font-weight: 600; color: white; background: linear-gradient(135deg, #dc2626, #b91c1c); border: none; cursor: pointer; font-size: 1rem; transition: all 0.2s; text-decoration: none; display: inline-block; }
                .btn-danger:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4); }
                .btn-secondary { padding: 0.75rem 2rem; border-radius: 0.5rem; font-weight: 600; color: #8b949e; background: #21262d; border: 1px solid #30363d; cursor: pointer; font-size: 1rem; transition: all 0.2s; text-decoration: none; display: inline-block; }
                .btn-secondary:hover { background: #30363d; color: #e0e0e0; }
                ul { text-align: left; margin: 1rem 0; padding-left: 1.5rem; }
                li { color: #8b949e; font-size: 0.9rem; margin: 0.4rem 0; }
                code { color: #f85149; font-size: 0.85rem; }
            </style>
        </head>
        <body>
            <div class="card">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🧹</div>
                <h1 class="text-2xl font-bold text-white mb-2">Limpieza Completa</h1>
                <p class="text-gray-400 text-sm mb-4">
                    Esto borrará <strong>TODOS</strong> los datos y volverá la aplicación
                    al estado de "recién instalada".
                </p>
                <div class="danger-zone">
                    <p class="text-red-400 font-semibold text-sm mb-2">⚠️ Se eliminará lo siguiente:</p>
                    <ul>
                        <li>🗄️ <strong>Todas las tablas</strong> de la base de datos</li>
                        <li>📁 <strong>Descargas</strong> — todo el directorio</li>
                        <li>📝 <strong>Archivos de log</strong></li>
                        <li>⚙️ <strong>Configuración</strong> — config.json reseteado</li>
                        <li>🚩 <strong>Señal de detención</strong></li>
                    </ul>
                    <p class="text-red-400 text-xs mt-3 font-bold">❗ Esta acción NO se puede deshacer</p>
                </div>
                <div class="flex gap-3 justify-center">
                    <a href="?" class="btn-secondary">Cancelar</a>
                    <a href="?confirm=yes" class="btn-danger">🧹 Sí, limpiar todo</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Execute the full cleanup.
     */
    private function executeCleanup(): void
    {
        header('Content-Type: text/html; charset=utf-8');

        // Load DB config from config.json
        $configFile = __DIR__ . '/../../config.json';
        $dbConfig = $this->loadDbConfig($configFile);

        $results = [];
        $errors = [];

        // ── 1. Clean database ──
        try {
            $pdo = new \PDO(
                "mysql:host={$dbConfig['host']};charset=utf8mb4",
                $dbConfig['user'],
                $dbConfig['pass'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 10,
                ]
            );

            $tables = [
                'log_descargas',
                'mangas_eliminados',
                'batch_historial',
                'batch_progreso',
                'comics_descargados',
            ];

            foreach ($tables as $table) {
                $pdo->exec("DROP TABLE IF EXISTS `{$dbConfig['name']}`.`{$table}`");
            }

            $results[] = '✅ Tablas de la base de datos eliminadas correctamente';
        } catch (\PDOException $e) {
            $errors[] = '⚠️ Error al eliminar tablas: ' . $e->getMessage();
        }

        // ── 2. Delete downloads directory ──
        $descargasDir = __DIR__ . '/../../descargas';
        if (is_dir($descargasDir)) {
            $this->deleteDirectory($descargasDir);
            if (@rmdir($descargasDir)) {
                $results[] = '✅ Directorio de descargas eliminado';
            } else {
                $errors[] = '⚠️ No se pudo eliminar el directorio de descargas';
            }
        } else {
            $results[] = 'ℹ️ No existía directorio de descargas';
        }

        // ── 3. Delete log files ──
        $logFile = __DIR__ . '/../../logs/scraper.log';
        if (file_exists($logFile)) {
            if (@unlink($logFile)) {
                $results[] = '✅ Archivo de log eliminado';
            } else {
                $errors[] = '⚠️ No se pudo eliminar el archivo de log';
            }
        } else {
            $results[] = 'ℹ️ No existía archivo de log';
        }

        // ── 4. Delete stop signal ──
        $stopFile = sys_get_temp_dir() . '/scraper_stop.flag';
        if (file_exists($stopFile)) {
            @unlink($stopFile);
            $results[] = '✅ Señal de detención eliminada';
        }

        // ── 5. Reset config.json to defaults ──
        $defaultConfig = $this->getDefaultConfig();
        $written = file_put_contents(
            $configFile,
            json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        if ($written !== false) {
            $results[] = '✅ Configuración reseteada a valores por defecto';
        } else {
            $errors[] = '⚠️ No se pudo resetear config.json';
        }

        // ── Show results ──
        $this->showResults($results, $errors);
    }

    /**
     * Load DB config from config.json.
     */
    private function loadDbConfig(string $configFile): array
    {
        $defaults = [
            'host' => 'localhost',
            'name' => 'comics_db',
            'user' => 'root',
            'pass' => '',
        ];

        if (file_exists($configFile)) {
            $cfg = json_decode(file_get_contents($configFile), true) ?: [];
            return [
                'host' => $cfg['db_host'] ?? 'localhost',
                'name' => $cfg['db_name'] ?? 'comics_db',
                'user' => $cfg['db_user'] ?? 'root',
                'pass' => $cfg['db_pass'] ?? '',
            ];
        }

        return $defaults;
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): void
    {
        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
    }

    /**
     * Show cleanup results page.
     */
    private function showResults(array $results, array $errors): void
    {
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>🧹 Limpieza Completa — Resultados</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                body { background: #0d1117; color: #e0e0e0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, -apple-system, sans-serif; }
                .card { background: #161b22; border: 1px solid #30363d; border-radius: 1rem; padding: 2.5rem; max-width: 600px; width: 100%; }
                .result-item { padding: 0.75rem 1rem; border-radius: 0.5rem; margin: 0.5rem 0; font-size: 0.9rem; }
                .result-item.success { background: rgba(46, 160, 67, 0.1); border: 1px solid rgba(46, 160, 67, 0.2); color: #3fb950; }
                .result-item.error { background: rgba(248, 81, 73, 0.1); border: 1px solid rgba(248, 81, 73, 0.2); color: #f85149; }
                .result-item.info { background: rgba(88, 166, 255, 0.1); border: 1px solid rgba(88, 166, 255, 0.2); color: #58a6ff; }
                .btn { padding: 0.75rem 2rem; border-radius: 0.5rem; font-weight: 600; color: white; background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; cursor: pointer; font-size: 1rem; transition: all 0.2s; text-decoration: none; display: inline-block; margin-top: 1.5rem; }
                .btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4); }
            </style>
        </head>
        <body>
            <div class="card">
                <div style="font-size: 3rem; text-align: center; margin-bottom: 1rem;">
                    <?php echo empty($errors) ? '✨' : '⚠️'; ?>
                </div>
                <h1 class="text-2xl font-bold text-white text-center mb-2">
                    <?php echo empty($errors) ? 'Limpieza Completada' : 'Limpieza con Advertencias'; ?>
                </h1>
                <p class="text-gray-400 text-sm text-center mb-4">La aplicación ha sido restaurada a su estado inicial.</p>
                <div class="space-y-1">
                    <?php foreach ($results as $r): ?>
                        <div class="result-item success"><?php echo htmlspecialchars($r); ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($errors as $e): ?>
                        <div class="result-item error"><?php echo htmlspecialchars($e); ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="text-center">
                    <a href="setup.php" class="btn">⚙️ Ir a Configuración</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Default config values for reset.
     */
    private function getDefaultConfig(): array
    {
        return [
            'db_host'         => 'localhost',
            'db_name'         => 'comics_db',
            'db_user'         => 'root',
            'db_pass'         => '',
            'site_base_url'   => 'https://sitio.com',
            'site_view_path'  => '/view',
            'site_batch_path' => '/parody',
            'site_view'       => '',
            'site_parody'     => '',
            'site_domain'     => '',
            'download_path'   => '',
            'delay_page_min'  => 1.5,
            'delay_page_max'  => 3.5,
            'delay_comic_min' => 5,
            'delay_comic_max' => 10,
            'max_retries'     => 2,
            'curl_ssl_verify' => false,
            'saved_at'        => date('Y-m-d H:i:s'),
        ];
    }
}
