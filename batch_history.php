<?php
/**
 * batch_history.php
 *
 * Thin wrapper — delegates to BatchHistoryController.
 *
 * @see src/Controllers/BatchHistoryController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autoload.php';

use ScrapApp\Controllers\BatchHistoryController;

(new BatchHistoryController())->index();
