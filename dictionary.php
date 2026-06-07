<?php
/**
 * dictionary.php
 *
 * Thin wrapper — delegates to DictionaryController.
 * The HTML template has been extracted to includes/dictionary_page.php.
 *
 * @see src/Controllers/DictionaryController.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autoload.php';

use ScrapApp\Controllers\DictionaryController;

(new DictionaryController())->handle();
