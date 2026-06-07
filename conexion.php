<?php
/**
 * conexion.php
 *
 * Conexión PDO a la base de datos MySQL.
 * Crea la conexión usando DatabaseConnection (sin DDL).
 * Las migraciones (CREATE TABLE) se ejecutan explícitamente en setup.php
 * o mediante el comando CLI src/Infrastructure/DatabaseMigration.php.
 *
 * @see src/Infrastructure/DatabaseConnection.php
 * @see src/Infrastructure/DatabaseMigration.php
 */

require_once __DIR__ . '/config.php';

// ── Verificar si hay configuración ──
// Si no existe config.json, redirigir a setup (excepto en peticiones AJAX)
if (!file_exists(__DIR__ . '/config.json')) {
    // Detectar si es petición AJAX o POST (scraper.php)
    $is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
               $_SERVER['REQUEST_METHOD'] === 'POST' ||
               !empty($_POST);
    
    if (!$is_ajax && PHP_SAPI !== 'cli') {
        header('Location: setup.php');
        exit;
    }
    
    // Para AJAX, devolver error JSON
    header('Content-Type: application/json');
    die(json_encode([
        'type'    => 'error',
        'message' => 'Configuración pendiente. Ve a setup.php para configurar la base de datos y la URL del sitio.'
    ]));
}

try {
    // Usar DatabaseConnection para obtener la conexión PDO (sin DDL)
    $pdo = \ScrapApp\Infrastructure\DatabaseConnection::getConnection();
} catch (\PDOException $e) {
    // Si falla la conexión y es AJAX, devolver JSON
    $is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
               $_SERVER['REQUEST_METHOD'] === 'POST';
    
    if ($is_ajax || !empty($_POST)) {
        header('Content-Type: application/json');
        die(json_encode([
            'type'    => 'error',
            'message' => 'Error de conexión a la base de datos: ' . $e->getMessage()
        ]));
    }
    
    // Para páginas normales, mostrar error
    die('<div style="background:#0d1117;color:#f85149;padding:2rem;font-family:monospace;text-align:center;min-height:100vh">' .
        '<h1 style="font-size:1.5rem">❌ Error de Conexión</h1>' .
        '<p style="color:#8b949e">' . htmlspecialchars($e->getMessage()) . '</p>' .
        '<a href="setup.php" style="color:#58a6ff;margin-top:1rem;display:inline-block">⚙️ Ir a configuración</a>' .
        '</div>');
}
