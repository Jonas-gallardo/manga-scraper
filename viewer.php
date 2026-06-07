<?php
/**
 * viewer.php
 *
 * Thin wrapper — delegates to ViewerController.
 *
 * @see src/Controllers/ViewerController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/conexion.php';

use ScrapApp\Controllers\ViewerController;

(new ViewerController())->handle();
