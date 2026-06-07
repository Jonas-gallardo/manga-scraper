<?php
/**
 * convert_to_webp.php
 *
 * Thin wrapper — delegates to ConvertToWebpController.
 *
 * @see src/Controllers/ConvertToWebpController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autoload.php';

use ScrapApp\Controllers\ConvertToWebpController;

(new ConvertToWebpController())->handle();
