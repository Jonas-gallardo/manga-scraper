<?php
/**
 * src/Controllers/StopScraperController.php
 *
 * Controller for stopping an active scraper process.
 * Creates a stop signal file that the scraper checks periodically.
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class StopScraperController extends BaseController
{
    /**
     * Create the stop signal.
     * POST only. Returns JSON confirmation.
     */
    public function stop(): void
    {
        $this->requirePost();

        $stopFile = defined('SCRAPER_STOP_FILE')
            ? SCRAPER_STOP_FILE
            : sys_get_temp_dir() . '/scraper_stop.flag';

        $written = @file_put_contents($stopFile, date('Y-m-d H:i:s'));

        if ($written === false) {
            // Fallback: try touch()
            $touched = @touch($stopFile);
            if (!$touched) {
                $this->json([
                    'success' => false,
                    'message' => 'No se pudo crear la señal de detención en: ' . $stopFile,
                ], 500);
            }
        }

        $this->json([
            'success' => true,
            'message' => '🚦 Señal de detención enviada. El scraper se detendrá en breve.',
            'file'    => $stopFile,
        ]);
    }
}
