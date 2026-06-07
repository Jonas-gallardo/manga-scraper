<?php
/**
 * harvest_tags.php
 *
 * Recolecta todas las etiquetas del listado de 3hentai.net (22 páginas)
 * y genera un reporte cruzado contra el diccionario actual.
 *
 * USO:
 *   php harvest_tags.php                  → Escanea todas las páginas y muestra reporte
 *   php harvest_tags.php --save=tags.json → Guarda el listado completo en JSON
 *   php harvest_tags.php --page=1         → Solo una página específica
 */

require_once __DIR__ . '/autoload.php';

use ScrapApp\Commands\HarvestTagsCommand;

// ── Parse arguments ──
$saveFile   = null;
$singlePage = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--save=(.+)$/', $arg, $m)) {
        $saveFile = $m[1];
    }
    if (preg_match('/^--page=(\d+)$/', $arg, $m)) {
        $singlePage = (int) $m[1];
    }
}

// ── Execute command ──
$command = new HarvestTagsCommand();
exit($command->execute($saveFile, $singlePage));
