<?php
/**
 * public/index.php
 *
 * Front Controller — Main entry point for Comic Scraper Pro.
 *
 * All requests are routed through this file to the appropriate controller
 * based on the route pattern. Supports both the Front Controller pattern
 * and backward compatibility with existing .php entry points.
 *
 * USAGE (with Apache rewrite):
 *   RewriteEngine On
 *   RewriteCond %{REQUEST_FILENAME} !-f
 *   RewriteCond %{REQUEST_FILENAME} !-d
 *   RewriteRule ^(.*)$ public/index.php [QSA,L]
 *
 * USAGE (direct):
 *   php -S localhost:8000 -t public/
 *
 * @package ScrapApp
 */

// ── Bootstrap ──
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../autoload.php';

use ScrapApp\Router;
use ScrapApp\Controllers\DashboardController;
use ScrapApp\Controllers\GalleryController;
use ScrapApp\Controllers\DeleteMangaController;
use ScrapApp\Controllers\BatchHistoryController;
use ScrapApp\Controllers\StopScraperController;
use ScrapApp\Controllers\SaveConfigController;
use ScrapApp\Controllers\ViewerController;
use ScrapApp\Controllers\CleanupController;
use ScrapApp\Controllers\SetupController;
use ScrapApp\Controllers\DictionaryController;
use ScrapApp\Controllers\ExportController;
use ScrapApp\Controllers\ConvertToWebpController;

// ── Setup Router ──
$router = new Router();

// ── API Routes (JSON) ──
$router->get('/api/dashboard', function () {
    (new DashboardController())->index();
});

$router->get('/api/gallery', function () {
    (new GalleryController())->index();
});

$router->get('/api/batch-history', function () {
    (new BatchHistoryController())->index();
});

$router->post('/api/delete-manga', function () {
    (new DeleteMangaController())->delete();
});

$router->post('/api/stop-scraper', function () {
    (new StopScraperController())->stop();
});

$router->any('/api/config', function () {
    $ctrl = new SaveConfigController();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ctrl->save();
    } else {
        $ctrl->read();
    }
});

// ── Page Routes (HTML) ──
$router->get('/setup', function () {
    (new SetupController())->index();
});

$router->get('/cleanup', function () {
    (new CleanupController())->handle();
});

$router->get('/export', function () {
    (new ExportController())->handle();
});

$router->get('/dictionary', function () {
    (new DictionaryController())->handle();
});

$router->post('/dictionary', function () {
    (new DictionaryController())->handle();
});

$router->any('/convert-to-webp', function () {
    (new ConvertToWebpController())->handle();
});

// ── Viewer Routes ──
$router->get('/viewer', function () {
    (new ViewerController())->handle();
});

// ── Legacy route: main app (index.php) ──
$router->get('/', function () {
    require __DIR__ . '/../index.php';
});

// ── Dispatch ──
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['REQUEST_URI'];

$router->dispatch($method, $uri);
