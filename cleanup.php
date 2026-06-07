<?php
/**
 * cleanup.php
 *
 * Thin wrapper — delegates to CleanupController.
 *
 * @see src/Controllers/CleanupController.php
 */

require_once __DIR__ . '/autoload.php';

use ScrapApp\Controllers\CleanupController;

(new CleanupController())->handle();
