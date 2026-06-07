<?php
/**
 * src/Controllers/SaveConfigController.php
 *
 * Controller for reading/writing application configuration.
 * Stores data in config.json.
 *
 * GET  → Returns current configuration
 * POST → Saves and tests new configuration, runs database migration
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

use ScrapApp\Infrastructure\DatabaseConnection;
use ScrapApp\Infrastructure\DatabaseMigration;

class SaveConfigController extends BaseController
{
    private string $configFile;

    public function __construct()
    {
        parent::__construct();
        $this->configFile = __DIR__ . '/../../config.json';
    }

    /**
     * GET: Read current configuration.
     */
    public function read(): void
    {
        if (file_exists($this->configFile)) {
            $config = json_decode(file_get_contents($this->configFile), true);
            // Mask password for security
            if (isset($config['db_pass']) && $config['db_pass'] !== '') {
                $config['db_pass_hint'] = '••••••••';
            }
            // Merge config fields to top level so JS can access them directly
            $this->json(array_merge([
                'success'     => true,
                'configurado' => true,
            ], $config));
        } else {
            $defaults = $this->getDefaultConfig();
            $this->json(array_merge([
                'success'     => true,
                'configurado' => false,
            ], $defaults));
        }
    }

    /**
     * POST: Save and test new configuration.
     * Also runs database migration (creates tables if they don't exist).
     * Supports both JSON body (from JS fetch) and form-data.
     */
    public function save(): void
    {
        $this->requirePost();

        $db_host        = trim($this->jsonBody('db_host', $this->postParam('db_host', 'localhost')));
        $db_name        = trim($this->jsonBody('db_name', $this->postParam('db_name', 'comics_db')));
        $db_user        = trim($this->jsonBody('db_user', $this->postParam('db_user', 'root')));
        $db_pass        = (string) ($this->jsonBody('db_pass', $this->postParam('db_pass', '')));
        $site_url       = trim($this->jsonBody('site_base_url', $this->postParam('site_base_url', 'https://sitio.com')));
        $site_view_path = '/' . ltrim(trim($this->jsonBody('site_view_path', $this->postParam('site_view_path', '/view'))), '/');
        $site_batch_path = '/' . ltrim(trim($this->jsonBody('site_batch_path', $this->postParam('site_batch_path', '/parody'))), '/');
        $download_path  = trim($this->jsonBody('download_path', $this->postParam('download_path', '')));

        // Validate site URL
        if (!preg_match('#^https?://[^/]+#', $site_url)) {
            $this->json([
                'success' => false,
                'message' => 'La URL del sitio no es válida',
            ], 400);
        }

        // Keep existing password if not provided
        if ($db_pass === '' && file_exists($this->configFile)) {
            $existing = json_decode(file_get_contents($this->configFile), true);
            if (isset($existing['db_pass']) && $existing['db_pass'] !== '') {
                $db_pass = $existing['db_pass'];
            }
        }

        // Validate download_path
        if ($download_path !== '') {
            if (strpos($download_path, '/') !== 0) {
                $download_path = __DIR__ . '/../../' . ltrim($download_path, '/');
            }
            if (!is_dir($download_path)) {
                if (!@mkdir($download_path, 0777, true)) {
                    $this->json([
                        'success' => false,
                        'message' => 'No se pudo crear el directorio de descargas: ' . $download_path,
                    ], 400);
                }
            }
            if (!is_writable($download_path)) {
                $this->json([
                    'success' => false,
                    'message' => 'El directorio de descargas no tiene permisos de escritura: ' . $download_path,
                ], 400);
            }
        }

        // Test database connection
        try {
            $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
            $test_pdo = new \PDO($dsn, $db_user, $db_pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            $this->json([
                'success' => false,
                'message' => 'Error de conexión a la base de datos: ' . $e->getMessage(),
            ], 400);
        }

        // Build derived URLs
        $site_view   = rtrim($site_url, '/') . '/' . ltrim($site_view_path, '/');
        $site_parody = rtrim($site_url, '/') . '/' . ltrim($site_batch_path, '/');
        $site_domain = parse_url($site_url, PHP_URL_HOST);

        // Save configuration
        $config = [
            'db_host'         => $db_host,
            'db_name'         => $db_name,
            'db_user'         => $db_user,
            'db_pass'         => $db_pass,
            'site_base_url'   => rtrim($site_url, '/'),
            'site_view_path'  => $site_view_path,
            'site_batch_path' => $site_batch_path,
            'site_view'       => $site_view,
            'site_parody'     => $site_parody,
            'site_domain'     => $site_domain,
            'download_path'   => $download_path !== '' ? $download_path : (__DIR__ . '/../../descargas'),
            'curl_ssl_verify' => (bool) ($this->jsonBody('curl_ssl_verify', $this->postParam('curl_ssl_verify', false))),
            'delay_page_min'  => (float) ($this->jsonBody('delay_page_min', $this->postParam('delay_page_min', 1.5))),
            'delay_page_max'  => (float) ($this->jsonBody('delay_page_max', $this->postParam('delay_page_max', 3.5))),
            'delay_comic_min' => (int) ($this->jsonBody('delay_comic_min', $this->postParam('delay_comic_min', 5))),
            'delay_comic_max' => (int) ($this->jsonBody('delay_comic_max', $this->postParam('delay_comic_max', 10))),
            'max_retries'     => (int) ($this->jsonBody('max_retries', $this->postParam('max_retries', 2))),
            'saved_at'        => date('Y-m-d H:i:s'),
        ];

        $written = file_put_contents(
            $this->configFile,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        if ($written === false) {
            $this->json([
                'success' => false,
                'message' => 'No se pudo escribir el archivo de configuración',
            ], 500);
        }

        // ── Run database migration (create tables if they don't exist) ──
        try {
            // Reset DatabaseConnection cache so it picks up the new config
            DatabaseConnection::reset();

            $pdo     = DatabaseConnection::createConnection();
            $migration = new DatabaseMigration($pdo);
            $result    = $migration->run();

            if ($result['success']) {
                $this->json([
                    'success' => true,
                    'message' => 'Configuración guardada, conexión verificada y tablas creadas exitosamente.',
                ]);
            } else {
                $failed = implode(', ', array_keys($result['errors']));
                $this->json([
                    'success' => false,
                    'message' => 'Configuración guardada, pero falló al crear las tablas: ' . $failed,
                ], 500);
            }
        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'message' => 'Configuración guardada, pero error al ejecutar migración: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Default configuration values.
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
            'download_path'   => __DIR__ . '/../../descargas',
            'delay_page_min'  => 1.5,
            'delay_page_max'  => 3.5,
            'delay_comic_min' => 5,
            'delay_comic_max' => 10,
            'max_retries'     => 2,
            'curl_ssl_verify' => false,
        ];
    }
}
