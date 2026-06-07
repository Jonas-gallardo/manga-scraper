<?php
/**
 * dashboard.php
 *
 * Thin wrapper — delegates to DashboardController.
 *
 * @see src/Controllers/DashboardController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autoload.php';

use ScrapApp\Controllers\DashboardController;

(new DashboardController())->index();
