<?php
/**
 * stop_scraper.php
 *
 * Thin wrapper — delegates to StopScraperController.
 *
 * @see src/Controllers/StopScraperController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/conexion.php';

use ScrapApp\Controllers\StopScraperController;

(new StopScraperController())->index();
