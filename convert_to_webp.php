<?php
/**
 * convert_to_webp.php
 *
 * Thin wrapper — delegates to ConvertToWebpController.
 *
 * @see src/Controllers/ConvertToWebpController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/conexion.php';

use ScrapApp\Controllers\ConvertToWebpController;

(new ConvertToWebpController())->index();
