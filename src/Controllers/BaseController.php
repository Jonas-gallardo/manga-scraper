<?php
/**
 * src/Controllers/BaseController.php
 *
 * Base class for all controllers with common helper methods.
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

abstract class BaseController
{
    /** @var \PDO|null */
    protected ?\PDO $pdo = null;

    /**
     * Constructor — lazy-loads PDO when needed via getPDO().
     */
    public function __construct()
    {
        // PDO is loaded on demand to avoid requiring conexion.php in constructor
    }

    /**
     * Get or initialize the PDO connection.
     * Requires config.php and conexion.php to have been loaded.
     */
    protected function getPDO(): \PDO
    {
        if ($this->pdo === null) {
            global $pdo;
            if (!isset($pdo) || !($pdo instanceof \PDO)) {
                // If conexion.php hasn't been loaded, try to load it
                if (!defined('DB_HOST')) {
                    require_once __DIR__ . '/../../config.php';
                }
                require_once __DIR__ . '/../../conexion.php';
            }
            $this->pdo = $pdo;
        }
        return $this->pdo;
    }

    /**
     * Send a JSON response and exit.
     */
    protected function json(mixed $data, int $status = 200): void
    {
        // Clean any previous output (PHP warnings, whitespace, etc.)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send an HTML response and exit.
     */
    protected function html(string $content, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
        exit;
    }

    /**
     * Redirect to another URL and exit.
     */
    protected function redirect(string $url, int $status = 302): void
    {
        http_response_code($status);
        header("Location: $url");
        exit;
    }

    /**
     * Get a GET parameter with an optional default value.
     */
    protected function getParam(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get a POST parameter with an optional default value.
     */
    protected function postParam(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /**
     * Get a JSON body parameter (for requests with Content-Type: application/json).
     * Parses the raw request body as JSON and returns the specified key or the full object.
     */
    protected function jsonBody(?string $key = null, mixed $default = null): mixed
    {
        static $body = null;
        if ($body === null) {
            $raw = file_get_contents('php://input');
            $body = $raw ? json_decode($raw, true) : [];
        }
        if ($key === null) {
            return $body ?: $default;
        }
        return $body[$key] ?? $default;
    }

    /**
     * Get a REQUEST parameter (GET or POST) with an optional default value.
     */
    protected function param(string $key, mixed $default = null): mixed
    {
        return $_REQUEST[$key] ?? $default;
    }

    /**
     * Check if the current request is POST.
     */
    protected function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * Check if the current request is GET.
     */
    protected function isGet(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'GET';
    }

    /**
     * Check if running in CLI mode.
     */
    protected function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * Validate that a required POST parameter exists.
     * Sends a JSON error response and exits if missing.
     */
    protected function requirePostParam(string $key): string
    {
        $value = trim($_POST[$key] ?? '');
        if ($value === '') {
            $this->json([
                'success' => false,
                'message' => "Parámetro requerido faltante: {$key}",
            ], 400);
        }
        return $value;
    }

    /**
     * Validate that the request method is POST.
     * Sends a JSON error response and exits if not.
     */
    protected function requirePost(): void
    {
        if (!$this->isPost()) {
            $this->json([
                'success' => false,
                'message' => 'Solo se aceptan peticiones POST',
            ], 405);
        }
    }

    /**
     * Format bytes to a human-readable string.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
