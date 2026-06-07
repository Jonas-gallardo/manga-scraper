<?php
/**
 * delete_manga.php
 *
 * Thin wrapper — delegates to DeleteMangaController.
 *
 * @see src/Controllers/DeleteMangaController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/conexion.php';

use ScrapApp\Controllers\DeleteMangaController;

(new DeleteMangaController())->delete();
