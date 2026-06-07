<?php
/**
 * save_config.php
 *
 * Thin wrapper — delegates to SaveConfigController.
 *
 * @see src/Controllers/SaveConfigController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';

// conexion.php is NOT needed here — SaveConfigController connects directly to test DB

use ScrapApp\Controllers\SaveConfigController;

$ctrl = new SaveConfigController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ctrl->save();
} else {
    $ctrl->read();
}
