<?php
/**
 * stop_publish.php
 *
 * Endpoint llamado por el botón "Detener" desde la UI de WP Publisher.
 * Crea un archivo de señal en /tmp para que publish_to_wp.php
 * detecte la parada y detenga la publicación en curso.
 *
 * Uso (POST):
 *   fetch('stop_publish.php', { method: 'POST' })
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
$written = @file_put_contents(PUBLISH_STOP_FILE, '1', LOCK_EX);

if ($written !== false) {
    echo json_encode(['success' => true, 'message' => 'Señal de detención enviada — la publicación se detendrá en breve']);
} else {
    // Fallback: intentar con touch
    $touched = @touch(PUBLISH_STOP_FILE);
    if ($touched) {
        echo json_encode(['success' => true, 'message' => 'Señal de detención enviada (touch)']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'No se pudo crear la señal de detención']);
    }
}
