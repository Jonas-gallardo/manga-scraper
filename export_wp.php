<?php
/**
 * export_wp.php
 *
 * Thin wrapper — delegates to ExportController.
 *
 * @see src/Controllers/ExportController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/conexion.php';

use ScrapApp\Controllers\ExportController;

(new ExportController())->index();
