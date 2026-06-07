<?php
/**
 * dictionary.php
 *
 * Thin wrapper — delegates to DictionaryController.
 *
 * @see src/Controllers/DictionaryController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/conexion.php';

use ScrapApp\Controllers\DictionaryController;

(new DictionaryController())->index();
