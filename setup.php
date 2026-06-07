<?php
/**
 * setup.php
 *
 * Thin wrapper — delegates to SetupController.
 *
 * @see src/Controllers/SetupController.php
 */

require_once __DIR__ . '/autoload.php';

use ScrapApp\Controllers\SetupController;

(new SetupController())->index();
