<?php
/**
 * stop_scraper.php
 *
 * Endpoint llamado por el botón "Detener" desde la UI.
 * Crea un archivo de señal en /tmp para que scraper.php
 * detecte la parada y limpie las descargas incompletas.
 *
 * Uso (POST):
 *   fetch('stop_scraper.php', { method: 'POST' })
 *
 * Respuesta: JSON { success: true/false }
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Solo POST']);
    exit;
}

// Escribir el archivo de señal
$written = @file_put_contents(SCRAPER_STOP_FILE, '1', LOCK_EX);

if ($written !== false) {
    echo json_encode(['success' => true, 'message' => 'Señal de detención enviada']);
} else {
    // Fallback: intentar con touch
    $touched = @touch(SCRAPER_STOP_FILE);
    if ($touched) {
        echo json_encode(['success' => true, 'message' => 'Señal de detención enviada (touch)']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'No se pudo crear la señal de detención']);
    }
}
