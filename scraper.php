<?php
/**
 * scraper.php
 *
 * CONTROLADOR PRINCIPAL DE SCRAPING — VERSIÓN REFACTORIZADA.
 * Anteriormente contenía 1731 líneas con toda la lógica mezclada.
 * Ahora es un controlador delgado que:
 *   1. Configura el entorno (output, BD, autoloading)
 *   2. Instancia los servicios vía inyección de dependencias
 *   3. Delega en ScraperService para la ejecución
 * MODOS:
 *   action=single  → Cómic individual (/view/ID)
 *   action=batch   → Universo completo (/parody/...) con control de rango
 * PARÁMETROS ADICIONALES (modo batch):
 *   start_page   → Número de página del listado por donde empezar (default: 1)
 *   max_comics   → Máximo de cómics a descargar (default: 50)
 * @package ComicScraper
 */

// ──────────────────────────────────────────────────────────────
// 0. CONFIGURACIÓN INICIAL
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(0);
ignore_user_abort(false);
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_implicit_flush(true);
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
// 1. DEPENDENCIAS
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/includes/TaxonomyProcessor.php';
use ScrapApp\Infrastructure\HttpClient;
use ScrapApp\Infrastructure\FileManager;
use ScrapApp\Repositories\ComicRepository;
use ScrapApp\Services\ProgressTracker;
use ScrapApp\Services\ScraperService;
// 2. INYECCIÓN DE DEPENDENCIAS
// TaxProcessor global (usado también en otros contextos)
$taxProcessor = new TaxonomyProcessor();
// Cliente HTTP
$httpClient = new HttpClient([
    'user_agent'       => USER_AGENT ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    'timeout'          => CURL_TIMEOUT ?? 30,
    'connect_timeout'  => CURL_CONNECT_TIMEOUT ?? 10,
    'max_redirects'    => CURL_MAXREDIRS ?? 5,
    'ssl_verify'       => CURL_SSL_VERIFY ?? false,
    'default_headers'  => defined('HTTP_HEADERS') ? unserialize(HTTP_HEADERS) : [],
]);
// Progreso y logging
$progressTracker = new ProgressTracker($pdo);
// Callback de progreso para FileManager
$progressCallback = function (array $data) use ($progressTracker): void {
    $progressTracker->sendProgress($data);
};
// Sistema de archivos
$fileManager = new FileManager($httpClient, $progressCallback);
// Repositorio de cómics
$comicRepository = new ComicRepository($pdo);
// Orquestador principal
$scraperService = new ScraperService(
    $progressTracker,
    $comicRepository,
    $fileManager,
    $httpClient,
    $taxProcessor
);
// 3. PROCESAMIENTO DE LA PETICIÓN
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $progressTracker->sendProgress(['type' => 'error', 'message' => 'Solo se aceptan peticiones POST']);
    exit;
$action = trim($_POST['action'] ?? '');
$url    = trim($_POST['url'] ?? '');
$start_page = max(1, (int) ($_POST['start_page'] ?? BATCH_DEFAULT_PAGE));
$max_comics = max(1, (int) ($_POST['max_comics'] ?? BATCH_DEFAULT_MAX));
if (empty($url)) {
    $progressTracker->sendProgress(['type' => 'error', 'message' => 'La URL es obligatoria']);
// 4. EJECUTAR SCRAPER
$scraperService->run($action, $url, [
    'start_page' => $start_page,
    'max_comics' => $max_comics,
