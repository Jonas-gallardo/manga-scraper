<?php

declare(strict_types=1);

namespace ScrapApp\Services;

use ScrapApp\Infrastructure\HttpClient;
use ScrapApp\Infrastructure\FileManager;
use ScrapApp\Infrastructure\HtmlParser;
use ScrapApp\Infrastructure\UrlParser;
use ScrapApp\Repositories\ComicRepository;

/**
 * ScraperService.php
 *
 * ORQUESTADOR principal del scraping.
 * Reemplaza la lógica completa de scraper.php (1731 líneas) por un servicio
 * orientado a objetos con inyección de dependencias.
 *
 * Coordina:
 * - ProgressTracker   → Progreso y logging
 * - ComicRepository   → Operaciones BD
 * - FileManager        → Operaciones filesystem
 * - HtmlParser         → Parseo de HTML (static)
 * - UrlParser          → Parseo de URLs (static)
 * - HttpClient         → Peticiones HTTP
 * - TaxonomyProcessor  → Procesamiento de taxonomías (externo)
 *
 * @package ScrapApp
 * @subpackage Services
 */
class ScraperService
{
    private ProgressTracker $progress;
    private ComicRepository $comicRepo;
    private FileManager $fileManager;
    private HttpClient $httpClient;
    private \TaxonomyProcessor $taxProcessor;

    private const MODE_SINGLE = 'single';
    private const MODE_BATCH  = 'batch';

    public function __construct(
        ProgressTracker $progress,
        ComicRepository $comicRepo,
        FileManager $fileManager,
        HttpClient $httpClient,
        \TaxonomyProcessor $taxProcessor
    ) {
        $this->progress    = $progress;
        $this->comicRepo   = $comicRepo;
        $this->fileManager = $fileManager;
        $this->httpClient  = $httpClient;
        $this->taxProcessor = $taxProcessor;
    }

    /**
     * Punto de entrada principal: ejecuta el scraper según la acción.
     *
     * @param string $action 'single' o 'batch'
     * @param string $url URL del cómic o universo
     * @param array<string, mixed> $params Parámetros adicionales
     */
    public function run(string $action, string $url, array $params = []): void
    {
        // ── Configuración inicial de output ──
        $this->setupOutput();

        // ── Validar acción ──
        if (!in_array($action, [self::MODE_SINGLE, self::MODE_BATCH], true)) {
            $this->progress->sendProgress([
                'type' => 'error',
                'message' => 'Acción no válida (use single o batch)'
            ]);
            return;
        }

        // ── Validar URL ──
        if (!UrlParser::validate($url, $action)) {
            $this->progress->sendProgress([
                'type'    => 'error',
                'message' => "La URL no coincide con el formato esperado para el modo " . strtoupper($action)
            ]);
            return;
        }

        // ── Log inicial ──
        $startPage = max(1, (int) ($params['start_page'] ?? 1));
        $maxComics = max(1, (int) ($params['max_comics'] ?? 50));
        $logExtra = ($action === self::MODE_BATCH) ? " (start_page=$startPage, max=$maxComics)" : '';
        $this->progress->logToFile("INICIO - Modo: $action - URL: $url" . $logExtra);

        // ── Limpiar señal de stop residual ──
        $this->progress->clearStopSignal();

        // ── Ejecutar modo correspondiente ──
        if ($action === self::MODE_SINGLE) {
            $this->runSingle($url);
        } else {
            $this->runBatch($url, $startPage, $maxComics);
        }
    }

    // ════════════════════════════════════════════════════════════
    //  CONFIGURACIÓN INICIAL
    // ════════════════════════════════════════════════════════════

    private function setupOutput(): void
    {
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
    }

    // ════════════════════════════════════════════════════════════
    //  MODO A — CÓMIC INDIVIDUAL
    // ════════════════════════════════════════════════════════════

    private function runSingle(string $url): void
    {
        $id = UrlParser::extractId($url);
        if ($id === null) {
            $this->progress->sendProgress([
                'type' => 'error',
                'message' => 'No se pudo extraer el ID del cómic de la URL'
            ]);
            return;
        }

        // ── 0. Verificar blacklist ──
        if ($this->comicRepo->isDeleted($id)) {
            $this->progress->sendProgress([
                'type'    => 'warning',
                'message' => "⛔ El cómic ID {$id} está en la lista de eliminados. No se descargará."
            ]);
            $this->progress->logToDb($id, 'warning', "Intento de descarga de manga eliminado (ID {$id})");
            return;
        }

        // ── 1. Obtener HTML y extraer metadatos ──
        $this->progress->sendProgress([
            'type'    => 'info',
            'message' => "📄 Obteniendo página del cómic: {$url}"
        ]);

        $html = $this->fetchHtml($url, $id);
        if ($html === null) {
            return;
        }

        $xpath = HtmlParser::createXPath($html);
        if (!$xpath) {
            $this->progress->sendProgress(['type' => 'error', 'message' => 'Error al parsear el HTML']);
            return;
        }

        // Extraer todos los metadatos
        $titulo       = HtmlParser::extractTitle($xpath, $html);
        $totalPaginas = HtmlParser::extractTotalPages($xpath, $html);
        $autor        = HtmlParser::extractAuthor($xpath, $html);
        $tags         = HtmlParser::extractTags($xpath, $html);
        $series       = HtmlParser::extractSeries($xpath);
        $personajes   = HtmlParser::extractCharacters($xpath);
        $categorias   = HtmlParser::extractCategories($xpath);
        $artista      = HtmlParser::extractArtists($xpath);

        // Forzar tipo "manga" para 3hentai
        if (defined('SITE_BASE') && stripos(SITE_BASE, '3hentai') !== false) {
            $categorias = 'manga';
        }

        $sinopsis     = HtmlParser::extractSynopsis($xpath, $html);
        $idioma       = HtmlParser::extractLanguage($xpath, $html);
        $rating       = HtmlParser::extractRating($xpath, $html);

        // Procesar taxonomías
        $taxData = $this->taxProcessor->processFromScraper([
            'tags'       => $tags,
            'universo'   => $series,
            'idioma'     => $idioma,
            'autor'      => $artista ?: $autor,
            'artista'    => $artista,
            'tipo'       => $categorias,
            'personajes' => $personajes,
        ]);
        $taxonomiasJson = json_encode($taxData, JSON_UNESCAPED_UNICODE);

        $this->progress->sendProgress([
            'type'    => 'info',
            'message' => "🏷️  Título: {$titulo}  |  📖 Páginas: {$totalPaginas}" .
                         ($artista ? "  |  ✍️ Artista: {$artista}" : ($autor ? "  |  ✍️ Autor: {$autor}" : "")) .
                         "  |  🏷️ Etiquetas: " . (count($taxData['etiquetas']) ? implode(', ', $taxData['etiquetas']) : 'ninguna')
        ]);

        // ── 2. Verificar duplicado / reanudación ──
        $duplicateCheck = $this->comicRepo->checkDuplicate(
            $id,
            $titulo,
            [$this->fileManager, 'scanImages'],
            [$this->progress, 'sendProgress']
        );

        if ($duplicateCheck['duplicate']) {
            $this->progress->logToDb($id, 'warning', "Intento de descarga duplicada (completo)");
            return;
        }

        $reanudar  = $duplicateCheck['resume'];
        $pagInicial = $duplicateCheck['start_page'];
        $existingData = $duplicateCheck['existing_data'];

        // ── 3. Crear / verificar directorio ──
        $dirPath = '';
        if ($reanudar && !empty($existingData['ruta_carpeta']) && is_dir($existingData['ruta_carpeta'])) {
            $dirPath = $existingData['ruta_carpeta'];
            $this->progress->sendProgress([
                'type'    => 'info',
                'message' => "📁 Usando carpeta existente: {$dirPath}"
            ]);
        } else {
            $dirPath = $this->fileManager->createComicDirectory($id, $titulo);
            if ($dirPath === null) {
                $this->progress->sendProgress(['type' => 'error', 'message' => "No se pudo crear el directorio"]);
                return;
            }
            $reanudar  = false;
            $pagInicial = 1;
        }

        // ── 4. Descargar cada página ──
        $paginasOk   = $reanudar ? (int) ($existingData['paginas_ok'] ?? 0) : 0;
        $paginasFail = $reanudar ? (int) ($existingData['paginas_fail'] ?? 0) : 0;

        for ($pag = $pagInicial; $pag <= $totalPaginas; $pag++) {
            // Verificar si el archivo ya existe
            $filename = str_pad((string) $pag, 3, '0', STR_PAD_LEFT);
            $existingImage = $this->fileManager->findImageFile($dirPath, $filename);

            if ($existingImage !== null) {
                $paginasOk++;
                $this->progress->sendProgress([
                    'type'    => 'success',
                    'message' => "✅ Página {$pag} ya existe, saltando..."
                ]);
                continue;
            }

            $imgUrl  = defined('SITE_VIEW') ? SITE_VIEW . "/{$id}/{$pag}" : '';
            if (empty($imgUrl)) {
                $this->progress->sendProgress([
                    'type'    => 'error',
                    'message' => "SITE_VIEW no está definido"
                ]);
                break;
            }

            $filepath = $dirPath . '/' . $filename . '.jpg';

            $this->progress->sendProgress([
                'type'    => 'progress',
                'current' => $pag,
                'total'   => $totalPaginas,
                'message' => "⬇️  Descargando página {$pag}/{$totalPaginas}..."
            ]);

            $retries = 0;
            $ok = $this->fileManager->downloadImage($imgUrl, $filepath, $retries);

            if ($ok) {
                $paginasOk++;
                $this->progress->sendProgress([
                    'type'    => 'success',
                    'message' => "✅ Página {$pag} guardada"
                ]);
            } else {
                $paginasFail++;
                $this->progress->sendProgress([
                    'type'    => 'warning',
                    'message' => "⚠️  Página {$pag} omitida tras fallos"
                ]);
            }

            // Check stop signal
            if ($this->progress->checkStopSignal()) {
                $this->progress->sendProgress([
                    'type'    => 'warning',
                    'message' => "⏹  Señal de detención recibida. Limpiando descarga incompleta..."
                ]);
                $this->fileManager->removeDirectoryRecursive($dirPath);
                $this->comicRepo->deleteIncomplete($id);
                $this->progress->logToFile("STOP single ID $id - descarga incompleta eliminada por señal de detención");
                return;
            }

            if ($pag < $totalPaginas) {
                $delayMin = defined('DELAY_PAGE_MIN') ? DELAY_PAGE_MIN : 1.5;
                $delayMax = defined('DELAY_PAGE_MAX') ? DELAY_PAGE_MAX : 3.5;
                $this->progress->delay($delayMin, $delayMax);
            }
        }

        // ── 5. Conversión a WebP ──
        $this->convertToWebP($dirPath, $id, $paginasOk);

        // ── 6. Guardar en BD ──
        $estadoFinal = ($paginasFail === 0) ? 'completo' : (($paginasOk > 0) ? 'parcial' : 'error');
        $this->comicRepo->save(
            $id, $titulo, null, $autor, $artista, $tags, $sinopsis,
            $idioma, $rating, $totalPaginas, $paginasOk, $paginasFail,
            $estadoFinal, $dirPath, $taxonomiasJson
        );

        $this->progress->logToDb($id, 'success', "Descarga {$estadoFinal}: {$paginasOk}/{$totalPaginas} páginas");

        $this->progress->sendProgress([
            'type'    => 'complete',
            'message' => "🎉 ¡CÓMIC DESCARGADO!  «{$titulo}» (ID {$id}) — {$paginasOk}/{$totalPaginas} páginas"
        ]);

        $this->progress->logToFile("FIN single ID $id - $titulo - Estado: $estadoFinal - $paginasOk/$totalPaginas páginas");
    }

    // ════════════════════════════════════════════════════════════
    //  MODO B — UNIVERSO / BATCH
    // ════════════════════════════════════════════════════════════

    private function runBatch(string $url, int $startPage, int $maxComics): void
    {
        $universo = UrlParser::extractUniverse($url) ?? 'Desconocido';

        $this->progress->sendProgress([
            'type'    => 'info',
            'message' => "🌌 Universo: «{$universo}»"
        ]);
        $this->progress->sendProgress([
            'type'    => 'info',
            'message' => "📄 Página inicial del listado: {$startPage}  |  Máx. cómics: {$maxComics}"
        ]);

        // ── Verificar historial: reanudar si ya fue procesado ──
        $ultimaPaginaHistorial = $this->comicRepo->getBatchLastPage($url);
        if ($ultimaPaginaHistorial > 0 && $startPage <= $ultimaPaginaHistorial) {
            $paginaResumen = $ultimaPaginaHistorial;
            $startPage = $ultimaPaginaHistorial + 1;

            $this->progress->sendProgress([
                'type'    => 'info',
                'message' => "📋 Historial encontrado para esta URL — última página procesada: {$paginaResumen}"
            ]);
            $this->progress->sendProgress([
                'type'    => 'info',
                'message' => "🔄 Reanudando automáticamente desde página {$startPage}"
            ]);
            $this->progress->logToFile("HISTORIAL: URL ya procesada hasta página $paginaResumen. Reanudando desde página $startPage.");
        }

        // ── Inicializar progreso batch en BD ──
        $this->comicRepo->initBatchProgress($universo, $url, $startPage, $maxComics);

        $comicsDescargados = 0;
        $comicsOmitidos    = 0;
        $comicsErrores     = 0;
        $stopDetected      = false;
        $currentPage       = $startPage;
        $paginaInicialEfectiva = $startPage;

        // ── Recolectar enlaces paginando ──
        $todosEnlaces = [];
        while (count($todosEnlaces) < $maxComics) {
            $htmlPagina = $this->fetchListHtml($url, $currentPage);
            if ($htmlPagina === null) {
                $this->progress->sendProgress([
                    'type'    => 'warning',
                    'message' => "No se pudo obtener la página {$currentPage} del listado. Deteniendo paginación."
                ]);
                break;
            }

            $enlacesPagina = HtmlParser::extractComicLinks($htmlPagina);

            if (empty($enlacesPagina)) {
                $this->progress->sendProgress([
                    'type'    => 'info',
                    'message' => "📭 No se encontraron más enlaces en página {$currentPage}. Fin del listado."
                ]);
                break;
            }

            $nuevos = 0;
            foreach ($enlacesPagina as $link) {
                if (!in_array($link, $todosEnlaces, true)) {
                    $todosEnlaces[] = $link;
                    $nuevos++;
                    if (count($todosEnlaces) >= $maxComics) {
                        break;
                    }
                }
            }

            $this->progress->sendProgress([
                'type'    => 'info',
                'message' => "📑 Página {$currentPage}: +{$nuevos} enlaces nuevos (total: " . count($todosEnlaces) . ")"
            ]);

            // Actualizar progreso BD
            $this->comicRepo->updateBatchProgress($universo, $currentPage, count($todosEnlaces));
            $this->comicRepo->updateBatchLastPage($url, $currentPage);

            $currentPage++;

            // Pausa entre páginas del listado
            $this->progress->delay(1.0, 2.0);
        }

        $totalComicsEncontrados = count($todosEnlaces);

        if ($totalComicsEncontrados === 0) {
            $this->progress->sendProgress([
                'type'    => 'error',
                'message' => "No se encontraron cómics en {$url}"
            ]);
            $this->comicRepo->finishBatchProgress($universo);
            return;
        }

        $this->progress->sendProgress([
            'type'    => 'info',
            'message' => "🔗 Se encontraron {$totalComicsEncontrados} cómics en total (páginas {$paginaInicialEfectiva}-" . ($currentPage - 1) . ")"
        ]);
        $this->progress->sendProgress([
            'type'    => 'divider',
            'message' => '═══════════════════════════════════════════════'
        ]);

        // ── Procesar cada cómic ──
        foreach ($todosEnlaces as $idx => $comicUrl) {
            if ($comicsDescargados >= $maxComics) {
                $this->progress->sendProgress([
                    'type'    => 'info',
                    'message' => "⏹️  Se alcanzó el límite de {$maxComics} cómics. Deteniendo."
                ]);
                break;
            }

            $idxHumano = $idx + 1;
            $this->progress->sendProgress([
                'type'    => 'divider',
                'message' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                             "  📚 Cómic {$idxHumano} de {$totalComicsEncontrados}\n" .
                             "  🔗 {$comicUrl}\n" .
                             "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
            ]);

            $id = UrlParser::extractId($comicUrl);
            if ($id === null) {
                $this->progress->sendProgress([
                    'type' => 'warning',
                    'message' => "No se pudo extraer ID, saltando..."
                ]);
                continue;
            }

            // ── Verificar blacklist ──
            if ($this->comicRepo->isDeleted($id)) {
                $this->progress->sendProgress([
                    'type'    => 'info',
                    'message' => "⛔ Cómic ID {$id} está en la lista de eliminados. Saltando..."
                ]);
                $comicsOmitidos++;
                $this->progress->logToDb($id, 'info', "Omitido por blacklist (batch)");
                $this->comicRepo->incrementBatchOmitted($universo);
                continue;
            }

            // ── Obtener HTML del cómic ──
            $htmlComic = $this->fetchHtml($comicUrl, $id);
            if ($htmlComic === null) {
                $comicsErrores++;
                continue;
            }

            $xpathComic = HtmlParser::createXPath($htmlComic);
            if (!$xpathComic) {
                $this->progress->sendProgress([
                    'type' => 'warning',
                    'message' => "Error parseando HTML del cómic {$id}, saltando..."
                ]);
                $comicsErrores++;
                continue;
            }

            $titulo = HtmlParser::extractTitle($xpathComic, $htmlComic);

            // ── Verificar duplicado ──
            $duplicateCheck = $this->comicRepo->checkDuplicate(
                $id,
                $titulo,
                [$this->fileManager, 'scanImages'],
                [$this->progress, 'sendProgress']
            );

            if ($duplicateCheck['duplicate']) {
                $this->progress->sendProgress([
                    'type'    => 'info',
                    'message' => "⏭️  Cómic «{$titulo}» (ID {$id}) ya descargado completamente. Saltando..."
                ]);
                $comicsOmitidos++;
                $this->progress->logToDb($id, 'info', "Omitido por duplicado (batch {$universo})");
                $this->comicRepo->incrementBatchOmitted($universo);
                continue;
            }

            // ── Extraer metadatos ──
            $totalPaginas = HtmlParser::extractTotalPages($xpathComic, $htmlComic);
            $autor        = HtmlParser::extractAuthor($xpathComic, $htmlComic);
            $tags         = HtmlParser::extractTags($xpathComic, $htmlComic);
            $series       = HtmlParser::extractSeries($xpathComic);
            $personajes   = HtmlParser::extractCharacters($xpathComic);
            $categorias   = HtmlParser::extractCategories($xpathComic);
            $sinopsis     = HtmlParser::extractSynopsis($xpathComic, $htmlComic);
            $idioma       = HtmlParser::extractLanguage($xpathComic, $htmlComic);
            $rating       = HtmlParser::extractRating($xpathComic, $htmlComic);
            $artista      = HtmlParser::extractArtists($xpathComic);

            if (defined('SITE_BASE') && stripos(SITE_BASE, '3hentai') !== false) {
                $categorias = 'manga';
            }

            // Combinar universo de batch + series del HTML
            $universoCombinado = $universo;
            if ($series !== null) {
                $universoCombinado = $series;
            }

            $taxData = $this->taxProcessor->processFromScraper([
                'tags'       => $tags,
                'universo'   => $universoCombinado,
                'idioma'     => $idioma,
                'autor'      => $artista ?: $autor,
                'artista'    => $artista,
                'tipo'       => $categorias,
                'personajes' => $personajes,
            ]);
            $taxonomiasJson = json_encode($taxData, JSON_UNESCAPED_UNICODE);

            $this->progress->sendProgress([
                'type'    => 'info',
                'message' => "🏷️  «{$titulo}» — {$totalPaginas} páginas" .
                             ($artista ? "  |  ✍️ Artista: {$artista}" : ($autor ? "  |  ✍️ {$autor}" : "")) .
                             "  |  🏷️ " . count($taxData['etiquetas']) . " etiquetas" .
                             "  |  🌐 " . ($taxData['idioma'] ?? '?')
            ]);

            // ── Crear directorio ──
            $dirPath = $this->fileManager->createComicDirectory($id, $titulo);
            if ($dirPath === null) {
                $comicsErrores++;
                continue;
            }

            // ── Descargar páginas ──
            $paginasOk   = 0;
            $paginasFail = 0;

            for ($pag = 1; $pag <= $totalPaginas; $pag++) {
                $filename = str_pad((string) $pag, 3, '0', STR_PAD_LEFT);
                $existingImage = $this->fileManager->findImageFile($dirPath, $filename);

                if ($existingImage !== null) {
                    $paginasOk++;
                    continue;
                }

                $imgUrl  = defined('SITE_VIEW') ? SITE_VIEW . "/{$id}/{$pag}" : '';
                if (empty($imgUrl)) break;

                $filepath = $dirPath . '/' . $filename . '.jpg';

                $this->progress->sendProgress([
                    'type'    => 'progress',
                    'current' => $pag,
                    'total'   => $totalPaginas,
                    'message' => "⬇️  Página {$pag}/{$totalPaginas}"
                ]);

                $retries = 0;
                $ok = $this->fileManager->downloadImage($imgUrl, $filepath, $retries);

                if ($ok) {
                    $paginasOk++;
                } else {
                    $paginasFail++;
                    $this->progress->sendProgress([
                        'type'    => 'warning',
                        'message' => "⚠️  Página {$pag} omitida"
                    ]);
                }

                if ($this->progress->checkStopSignal()) {
                    $this->progress->sendProgress([
                        'type'    => 'warning',
                        'message' => "⏹  Señal de detención. Limpiando descarga incompleta del cómic actual..."
                    ]);
                    $this->fileManager->removeDirectoryRecursive($dirPath);
                    $this->comicRepo->deleteIncomplete($id);
                    $this->progress->logToFile("STOP batch ID $id (comic $idxHumano) - descarga incompleta eliminada");
                    $stopDetected = true;
                    break;
                }

                if ($pag < $totalPaginas) {
                    $delayMin = defined('DELAY_PAGE_MIN') ? DELAY_PAGE_MIN : 1.5;
                    $delayMax = defined('DELAY_PAGE_MAX') ? DELAY_PAGE_MAX : 3.5;
                    $this->progress->delay($delayMin, $delayMax);
                }
            }

            if ($stopDetected) {
                break;
            }

            // ── Conversión a WebP ──
            $this->convertToWebP($dirPath, $id, $paginasOk);

            // ── Guardar en BD ──
            $estadoFinal = ($paginasFail === 0) ? 'completo' : (($paginasOk > 0) ? 'parcial' : 'error');
            $this->comicRepo->save(
                $id, $titulo, $universo, $autor, $artista, $tags, $sinopsis,
                $idioma, $rating, $totalPaginas, $paginasOk, $paginasFail,
                $estadoFinal, $dirPath, $taxonomiasJson
            );

            if ($paginasOk > 0) {
                $comicsDescargados++;
                $this->progress->sendProgress([
                    'type'    => 'complete',
                    'message' => "✅ «{$titulo}» — {$paginasOk}/{$totalPaginas} páginas"
                ]);
            } else {
                $comicsErrores++;
                $this->progress->sendProgress([
                    'type'    => 'error',
                    'message' => "❌ «{$titulo}» — Falló la descarga"
                ]);
            }

            $this->progress->logToDb($id, 'success', "Batch {$universo}: {$estadoFinal} ({$paginasOk}/{$totalPaginas})");
            $this->comicRepo->incrementBatchDownloaded($universo, $estadoFinal === 'error');

            // Check stop signal between comics
            if ($this->progress->checkStopSignal()) {
                $stopDetected = true;
                $this->progress->sendProgress([
                    'type'    => 'warning',
                    'message' => "⏹  Señal de detención recibida. Deteniendo proceso batch..."
                ]);
                $this->progress->logToFile("STOP batch $universo - detenido entre cómics (después de ID $id)");
                break;
            }

            // Pausa entre cómics
            if ($idx < count($todosEnlaces) - 1 && $comicsDescargados < $maxComics) {
                $delayMin = defined('DELAY_COMIC_MIN') ? DELAY_COMIC_MIN : 5;
                $delayMax = defined('DELAY_COMIC_MAX') ? DELAY_COMIC_MAX : 10;
                $this->progress->delay($delayMin, $delayMax);
            }
        }

        // ── Si se detuvo por señal ──
        if ($stopDetected) {
            $this->comicRepo->finishBatchProgress($universo);
            $this->progress->sendProgress([
                'type'    => 'done',
                'message' => "⏹  PROCESO DETENIDO POR USUARIO\n" .
                             "  • Universo: «{$universo}»\n" .
                             "  • Cómics descargados: {$comicsDescargados}\n" .
                             "  • Omitidos (duplicados): {$comicsOmitidos}\n" .
                             "  • Errores: {$comicsErrores}"
            ]);
            $this->progress->logToFile("FIN STOP batch $universo - Detenido por usuario. Descargados: $comicsDescargados, Omitidos: $comicsOmitidos, Errores: $comicsErrores");
            return;
        }

        // ── Finalizar batch ──
        $this->comicRepo->finishBatchProgress($universo);

        $ultimaPaginaProcesada = $currentPage - 1;
        $this->comicRepo->saveBatchHistory(
            $url, $universo,
            $paginaInicialEfectiva, $ultimaPaginaProcesada,
            $maxComics, $totalComicsEncontrados,
            $comicsDescargados, $comicsOmitidos, $comicsErrores,
            true
        );

        $this->progress->sendProgress([
            'type'    => 'done',
            'message' => "🎉 ¡PROCESO COMPLETADO!\n" .
                         "  • Universo: «{$universo}»\n" .
                         "  • Cómics encontrados: {$totalComicsEncontrados}\n" .
                         "  • Descargados: {$comicsDescargados}\n" .
                         "  • Omitidos (duplicados): {$comicsOmitidos}\n" .
                         "  • Errores: {$comicsErrores}\n" .
                         "  • Páginas del listado: {$paginaInicialEfectiva} - {$ultimaPaginaProcesada}\n" .
                         ($ultimaPaginaHistorial > 0 ? "  • Reanudado desde historial: sí (previo hasta pág. {$ultimaPaginaHistorial})\n" : "")
        ]);

        $this->progress->logToFile("FIN batch $universo - Descargados: $comicsDescargados, Omitidos: $comicsOmitidos, Errores: $comicsErrores");
    }

    // ════════════════════════════════════════════════════════════
    //  MÉTODOS AUXILIARES
    // ════════════════════════════════════════════════════════════

    /**
     * Obtiene el HTML de una página con reintentos.
     */
    private function fetchHtml(string $url, int $id): ?string
    {
        $maxRetries = defined('MAX_RETRIES') ? MAX_RETRIES : 2;
        $retries = 0;

        while ($retries <= $maxRetries) {
            $html = $this->httpClient->fetchPage($url);

            if ($html !== null) {
                return $html;
            }

            $retries++;

            if ($retries <= $maxRetries) {
                $retryWait = defined('RETRY_WAIT_SECONDS') ? RETRY_WAIT_SECONDS : 10;
                $this->progress->sendProgress([
                    'type'    => 'warning',
                    'message' => "Error obteniendo HTML — Reintento $retries/$maxRetries en {$retryWait} s..."
                ]);
                sleep($retryWait);
            }
        }

        $this->progress->sendProgress([
            'type'    => 'error',
            'message' => "Fallo definitivo tras $maxRetries reintentos ($url)"
        ]);
        $this->progress->logToDb($id, 'error', "Fallo al obtener HTML");
        return null;
    }

    /**
     * Obtiene el HTML de una página del listado con paginación.
     */
    private function fetchListHtml(string $baseUrl, int $page): ?string
    {
        $sep = (strpos($baseUrl, '?') === false) ? '?' : '&';
        $pageParam = defined('BATCH_PAGE_PARAM') ? BATCH_PAGE_PARAM : 'page';
        $url = $baseUrl . $sep . $pageParam . '=' . $page;

        $this->progress->sendProgress([
            'type'    => 'info',
            'message' => "📄 Obteniendo página $page del listado..."
        ]);

        return $this->httpClient->fetchPage($url);
    }

    /**
     * Convierte imágenes del directorio a WebP con reporte de progreso.
     */
    private function convertToWebP(string $dirPath, int $id, int $paginasOk): void
    {
        if ($paginasOk <= 0 || !is_dir($dirPath)) {
            return;
        }

        $this->progress->sendProgress([
            'type'    => 'info',
            'message' => "🔄 Convirtiendo imágenes a WebP al 85% de calidad..."
        ]);

        $webpStats = $this->fileManager->convertToWebP($dirPath, 85);

        if ($webpStats['converted'] > 0) {
            $ahorroFormateado = ProgressTracker::formatBytes($webpStats['bytes_ahorrados']);
            $this->progress->sendProgress([
                'type'    => 'success',
                'message' => "✅ WebP: {$webpStats['converted']} imágenes convertidas, ahorrado {$ahorroFormateado}"
            ]);
        } elseif ($webpStats['skipped'] > 0) {
            $this->progress->sendProgress([
                'type'    => 'success',
                'message' => "✅ WebP: {$webpStats['skipped']} imágenes ya estaban en WebP"
            ]);
        }

        if ($webpStats['failed'] > 0) {
            $this->progress->sendProgress([
                'type'    => 'warning',
                'message' => "⚠️ WebP: {$webpStats['failed']} imágenes fallaron en la conversión"
            ]);
            $this->progress->logToDb($id, 'warning', "WebP: {$webpStats['failed']} fallos de conversión");
        }
    }
}
