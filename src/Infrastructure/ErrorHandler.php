<?php
/**
 * src/Infrastructure/ErrorHandler.php
 *
 * Centralized error and exception handler.
 * Detects AJAX vs normal requests and returns appropriate format.
 * Logs errors to a file for debugging.
 *
 * Usage: ErrorHandler::register();
 *
 * @package ScrapApp\Infrastructure
 */

namespace ScrapApp\Infrastructure;

class ErrorHandler
{
    /** @var string Path to the error log file */
    private static string $logFile = '';

    /** @var bool Whether the handler has been registered */
    private static bool $registered = false;

    /** @var int Old error_reporting level to restore for display */
    private static int $oldErrorReporting;

    /**
     * Register the error and exception handlers.
     *
     * If already registered, only updates the log file path (no handler re-registration).
     *
     * @param string|null $logFile Optional custom log file path.
     */
    public static function register(?string $logFile = null): void
    {
        // Update log file path even if already registered
        if ($logFile !== null) {
            self::$logFile = $logFile;
        } elseif (self::$logFile === '') {
            self::$logFile = dirname(__DIR__, 2) . '/data/error.log';
        }

        // Ensure the log directory exists
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        if (self::$registered) {
            return;
        }

        // Store old error_reporting
        self::$oldErrorReporting = error_reporting();

        // Set custom handlers
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);

        self::$registered = true;
    }

    /**
     * Handle uncaught exceptions.
     *
     * @param \Throwable $e
     */
    public static function handleException(\Throwable $e): void
    {
        self::logError($e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $statusCode = 500;
        $message    = 'Error interno del servidor';

        // Don't expose internal details in production
        if (self::isDebugMode()) {
            $message = $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
        }

        self::respond($statusCode, $message);
    }

    /**
     * Handle PHP errors (warnings, notices, etc.).
     *
     * @param int    $severity
     * @param string $message
     * @param string $file
     * @param int    $line
     * @return bool
     */
    public static function handleError(
        int    $severity,
        string $message,
        string $file = '',
        int    $line = 0
    ): bool {
        // Respect error_reporting setting
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // Log the error
        $type = self::getErrorType($severity);
        self::logError("[{$type}] {$message}", [
            'file' => $file,
            'line' => $line,
        ]);

        // Only halt execution for fatal errors
        if (in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            self::respond(500, self::isDebugMode() ? "{$type}: {$message}" : 'Error interno del servidor');
            return true; // Will exit
        }

        // For non-fatal errors, let PHP's default handler run
        return false;
    }

    /**
     * Send an appropriate response based on request type.
     *
     * @param int    $statusCode HTTP status code
     * @param string $message    Error message
     */
    private static function respond(int $statusCode, string $message): void
    {
        // Clean any previous output
        if (ob_get_level() > 0) {
            ob_clean();
        }

        http_response_code($statusCode);

        if (self::isAjaxRequest() || PHP_SAPI === 'cli') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'type'    => 'error',
                'success' => false,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo self::renderHtmlError($statusCode, $message);
        }

        exit;
    }

    /**
     * Detect if the current request is AJAX.
     *
     * @return bool
     */
    private static function isAjaxRequest(): bool
    {
        return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
               $_SERVER['REQUEST_METHOD'] === 'POST' ||
               !empty($_POST);
    }

    /**
     * Check if debug mode is enabled (display detailed errors).
     *
     * @return bool
     */
    private static function isDebugMode(): bool
    {
        return self::$oldErrorReporting !== 0;
    }

    /**
     * Render a styled HTML error page.
     *
     * @param int    $statusCode
     * @param string $message
     * @return string
     */
    private static function renderHtmlError(int $statusCode, string $message): string
    {
        $codeName = match ($statusCode) {
            400 => 'Solicitud Inválida',
            403 => 'Acceso Denegado',
            404 => 'Página No Encontrada',
            405 => 'Método No Permitido',
            500 => 'Error Interno del Servidor',
            default => 'Error',
        };

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode} — {$codeName}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0d1117;
            color: #e0e0e0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .error-card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 1rem;
            padding: 2.5rem;
            max-width: 520px;
            width: 100%;
            text-align: center;
        }
        .error-code {
            font-size: 4rem;
            font-weight: 800;
            background: linear-gradient(135deg, #f85149, #d63a3a);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
        }
        .error-title {
            color: #8b949e;
            font-size: 1.1rem;
            margin-top: 0.5rem;
        }
        .error-message {
            color: #f0f0f0;
            font-size: 0.9rem;
            margin-top: 1.5rem;
            padding: 1rem;
            background: #0d1117;
            border-radius: 0.5rem;
            border: 1px solid #30363d;
            font-family: 'Consolas', 'Monaco', monospace;
            word-break: break-word;
        }
        .error-link {
            display: inline-block;
            margin-top: 1.5rem;
            padding: 0.7rem 2rem;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            text-decoration: none;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .error-link:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-code">{$statusCode}</div>
        <div class="error-title">⚡ {$codeName}</div>
        <div class="error-message">" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</div>
        <a href="index.php" class="error-link">← Volver al inicio</a>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Write an error entry to the log file.
     *
     * @param string $message
     * @param array  $context
     */
    private static function logError(string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $ip        = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $method    = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri       = $_SERVER['REQUEST_URI'] ?? 'CLI';

        $logLine = "[{$timestamp}] [{$ip}] {$method} {$uri}" . PHP_EOL;
        $logLine .= "  → {$message}" . PHP_EOL;

        if (!empty($context['file'])) {
            $logLine .= "  → Archivo: {$context['file']}" . PHP_EOL;
        }
        if (!empty($context['line'])) {
            $logLine .= "  → Línea: {$context['line']}" . PHP_EOL;
        }
        if (!empty($context['trace'])) {
            $logLine .= "  → Traza:" . PHP_EOL;
            $logLine .= "    " . str_replace("\n", "\n    ", $context['trace']) . PHP_EOL;
        }
        $logLine .= PHP_EOL;

        @file_put_contents(self::$logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Map PHP error constant to human-readable type.
     *
     * @param int $severity
     * @return string
     */
    private static function getErrorType(int $severity): string
    {
        return match ($severity) {
            E_ERROR             => 'Fatal Error',
            E_WARNING           => 'Warning',
            E_PARSE             => 'Parse Error',
            E_NOTICE            => 'Notice',
            E_CORE_ERROR        => 'Core Error',
            E_CORE_WARNING      => 'Core Warning',
            E_COMPILE_ERROR     => 'Compile Error',
            E_COMPILE_WARNING   => 'Compile Warning',
            E_USER_ERROR        => 'User Error',
            E_USER_WARNING      => 'User Warning',
            E_USER_NOTICE       => 'User Notice',
            E_STRICT            => 'Strict Standards',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED        => 'Deprecated',
            E_USER_DEPRECATED   => 'User Deprecated',
            default             => 'Unknown Error',
        };
    }

    /**
     * Unregister the handlers (useful for testing).
     */
    public static function unregister(): void
    {
        if (self::$registered) {
            restore_exception_handler();
            restore_error_handler();
            self::$registered = false;
        }
    }

    /**
     * Get the current log file path.
     *
     * @return string
     */
    public static function getLogFile(): string
    {
        return self::$logFile;
    }
}
