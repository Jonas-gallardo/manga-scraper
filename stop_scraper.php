<?php
/**
 * stop_scraper.php
 *
 * Thin wrapper — delegates to StopScraperController.
 *
 * @see src/Controllers/StopScraperController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autoload.php';

use ScrapApp\Controllers\StopScraperController;

(new StopScraperController())->stop();
