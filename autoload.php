<?php

/**
 * autoload.php
 *
 * Bootstrap principal de la aplicación.
 * 1. Carga el autoloader (Composer preferido, fallback manual).
 * 2. Registra el manejador centralizado de errores y excepciones.
 *
 * @package ScrapApp
 */

// ── 1. Autoloader ──
// Composer autoloader (preferido)
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    // Fallback: autoloader PSR-4 manual
    spl_autoload_register(function (string $class): void {
        $prefix = 'ScrapApp\\';
        $baseDir = __DIR__ . '/src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

// ── 2. Error Handler centralizado ──
// Se registra después del autoloader para que la clase ErrorHandler esté disponible
\ScrapApp\Infrastructure\ErrorHandler::register();
