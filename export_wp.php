<?php
/**
 * export_wp.php
 *
 * Thin wrapper — delegates to ExportController.
 *
 * @see src/Controllers/ExportController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autoload.php';

use ScrapApp\Controllers\ExportController;

(new ExportController())->handle();
