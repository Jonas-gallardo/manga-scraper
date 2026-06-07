<?php
/**
 * harvest_series.php
 *
 * Recolecta todas las series/universos del listado de 3hentai.net (18 páginas)
 * y genera un reporte cruzado contra la lista de universos existentes.
 *
 * USO:
 *   php harvest_series.php                  → Escanea todas las páginas y muestra reporte
 *   php harvest_series.php --save=series.json → Guarda el listado completo en JSON
 */

require_once __DIR__ . '/autoload.php';

use ScrapApp\Commands\HarvestSeriesCommand;

// ── Parse arguments ──
$saveFile = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--save=(.+)$/', $arg, $m)) {
        $saveFile = $m[1];
    }
}

// ── Execute command ──
$command = new HarvestSeriesCommand();
exit($command->execute($saveFile));
