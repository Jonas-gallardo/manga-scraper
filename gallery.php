<?php
/**
 * gallery.php
 *
 * Thin wrapper — delegates to GalleryController.
 *
 * @see src/Controllers/GalleryController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autoload.php';

use ScrapApp\Controllers\GalleryController;

(new GalleryController())->index();
