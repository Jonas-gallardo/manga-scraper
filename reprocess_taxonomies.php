<?php
/**
 * reprocess_taxonomies.php
 *
 * Thin wrapper — delegates to ReprocessTaxonomiesController.
 *
 * @see src/Controllers/ReprocessTaxonomiesController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/conexion.php';

use ScrapApp\Controllers\ReprocessTaxonomiesController;

(new ReprocessTaxonomiesController())->index();
