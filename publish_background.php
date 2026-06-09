<?php
/**
 * publish_background.php
 *
 * Script CLI para ejecutar la publicación a WordPress en un proceso en
 * segundo plano (background). Diseñado para ser invocado desde
 * publish_to_wp.php mediante exec("php publish_background.php ... &").
 *
 * Al ejecutarse en CLI, NO tiene límite de tiempo (PHP-FPM no interviene),
 * solucionando el error ERR_CONNECTION_RESET que ocurría cuando el proceso
 * HTTP se alargaba más allá del request_terminate_timeout de PHP-FPM.
 *
 * USO (CLI):
 *   php publish_background.php --action=pending [--limit=10] [--comic-id=123]
 *
 * @package ScrapApp
 */

// ──────────────────────────────────────────────────────────────
// 1. CONFIGURACIÓN CLI (sin límite de tiempo)
// ──────────────────────────────────────────────────────────────

// En CLI no hay límite de tiempo de ejecución
if (PHP_SAPI === 'cli') {
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '1024M');
    // Buffer de salida: línea a línea para no acumular memoria
    ini_set('output_buffering', 0);
    if (ob_get_level()) {
        ob_end_flush();
    }
}

// ──────────────────────────────────────────────────────────────
// 2. CARGAR DEPENDENCIAS
// ──────────────────────────────────────────────────────────────

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/src/WP/WPClient.php';
require_once __DIR__ . '/src/WP/WPTaxonomySync.php';
require_once __DIR__ . '/src/WP/WPPublisher.php';

// ──────────────────────────────────────────────────────────────
// 3. PARSEAR ARGUMENTOS CLI
// ──────────────────────────────────────────────────────────────

$options = getopt('', [
    'action:',
    'limit:',
    'comic-id:',
    'help',
]);

if (isset($options['help'])) {
    echo "USO: php publish_background.php --action=<action> [--limit=N] [--comic-id=N]\n\n";
    echo "Acciones:\n";
    echo "  pending   Publicar todos los cómics pendientes (o N con --limit)\n";
    echo "  batch     Publicar un lote de N cómics (requiere --limit)\n";
    echo "  single    Publicar un cómic individual (requiere --comic-id)\n\n";
    exit(0);
}

$action   = $options['action'] ?? '';
$limit    = isset($options['limit']) ? (int) $options['limit'] : 0;
$comicId  = isset($options['comic-id']) ? (int) $options['comic-id'] : 0;

if (empty($action)) {
    fwrite(STDERR, "ERROR: Se requiere --action (pending|batch|single)\n");
    exit(1);
}

// ──────────────────────────────────────────────────────────────
// 4. FUNCIONES AUXILIARES (LOCK)
// ──────────────────────────────────────────────────────────────

/**
 * Obtiene la configuración de WordPress desde config.json.
 */
function getWPConfig(): ?array
{
    $configFile = __DIR__ . '/config.json';
    $jsonConfig = [];

    if (file_exists($configFile)) {
        $jsonConfig = json_decode(file_get_contents($configFile), true) ?: [];
    }

    $baseUrl  = $jsonConfig['wp_base_url'] ?? 'http://localhost:10003';
    $username = $jsonConfig['wp_username'] ?? 'admin';
    $password = $jsonConfig['wp_app_password'] ?? 'VheC eIHM 6W0q cMkQ nARp l1Id';

    if (empty($baseUrl) || empty($username) || empty($password)) {
        return null;
    }

    return [
        'base_url'  => rtrim($baseUrl, '/'),
        'username'  => $username,
        'password'  => $password,
    ];
}

/**
 * Adquiere el lock de publicación.
 *
 * @return bool True si se adquirió el lock, false si ya está en ejecución.
 */
function acquireLock(): bool
{
    $lockData = null;
    if (file_exists(PUBLISH_LOCK_FILE)) {
        $lockData = @json_decode(file_get_contents(PUBLISH_LOCK_FILE), true);
    }

    if ($lockData !== null && isset($lockData['pid'])) {
        // Verificar si el proceso aún está vivo
        $pid = (int) $lockData['pid'];
        if (posix_kill($pid, 0)) {
            fwrite(STDERR, "ERROR: Ya hay un proceso de publicación en ejecución (PID: {$pid})\n");
            return false;
        } else {
            // Proceso huérfano, limpiar
            fwrite(STDERR, "AVISO: Lock huérfano (PID {$pid} no existe). Limpiando...\n");
            @unlink(PUBLISH_LOCK_FILE);
        }
    }

    // Escribir nuevo lock
    $newLock = [
        'pid'       => getmypid(),
        'started'   => date('c'),
        'action'    => $GLOBALS['action'] ?? 'unknown',
    ];

    $written = @file_put_contents(PUBLISH_LOCK_FILE, json_encode($newLock, JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        fwrite(STDERR, "ERROR: No se pudo escribir el archivo de lock\n");
        return false;
    }

    return true;
}

/**
 * Libera el lock de publicación.
 */
function releaseLock(): void
{
    if (file_exists(PUBLISH_LOCK_FILE)) {
        @unlink(PUBLISH_LOCK_FILE);
    }
}

/**
 * Maneja errores fatales y garantiza que el lock se libere.
 */
function fatalErrorHandler(): void
{
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        releaseLock();
        // Escribir estado de error en el progreso
        $progress = WPPublisher::readProgressFile();
        if ($progress !== null) {
            $progress['status'] = 'error';
            $progress['error']  = $error['message'] ?? 'Error fatal desconocido';
            $progress['ended']  = date('c');
            @file_put_contents(PUBLISH_PROGRESS_FILE, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
}

register_shutdown_function('fatalErrorHandler');

// ──────────────────────────────────────────────────────────────
// 5. ADQUIRIR LOCK
// ──────────────────────────────────────────────────────────────

if (!acquireLock()) {
    exit(1);
}

// ──────────────────────────────────────────────────────────────
// 6. INICIALIZAR COMPONENTES
// ──────────────────────────────────────────────────────────────

try {
    $wpConfig = getWPConfig();
    if ($wpConfig === null) {
        fwrite(STDERR, "ERROR: Configuración de WordPress no encontrada.\n");
        releaseLock();
        exit(1);
    }

    $client = new WPClient(
        $wpConfig['base_url'],
        $wpConfig['username'],
        $wpConfig['password'],
        ['timeout' => 120, 'max_retries' => 5]
    );

    $taxonomySync = new WPTaxonomySync($client);
    $publisher = new WPPublisher($client, $taxonomySync, $pdo);

} catch (Exception $e) {
    fwrite(STDERR, "ERROR inicializando: " . $e->getMessage() . "\n");
    releaseLock();
    exit(1);
}

// ──────────────────────────────────────────────────────────────
// 7. EJECUTAR ACCIÓN SOLICITADA
// ──────────────────────────────────────────────────────────────
//
// NOTA: Los métodos publishPending(), publishBatch() y publishComic()
// manejan internamente initProgressFile(), markProgressSuccess() y
// markProgressError(). No llamamos a esos métodos privados directamente.

$exitCode = 0;

try {
    switch ($action) {
        case 'single':
            if ($comicId <= 0) {
                fwrite(STDERR, "ERROR: Se requiere --comic-id válido para action=single\n");
                releaseLock();
                exit(1);
            }
            // publishComic() NO inicializa progress internamente en individual,
            // así que escribimos un progreso básico manualmente
            $progressData = [
                'status'        => 'publishing',
                'current_comic' => null,
                'stats'         => [],
                'log'           => [],
                'timestamp'     => time(),
            ];
            @file_put_contents(PUBLISH_PROGRESS_FILE, json_encode($progressData, JSON_UNESCAPED_UNICODE), LOCK_EX);
            $result = $publisher->publishComic($comicId);
            break;

        case 'batch':
            $limit = $limit > 0 ? $limit : 10;
            // publishPending() -> publishBatch() maneja progress internamente
            $publisher->publishPending($limit);
            break;

        case 'pending':
            $limitForPending = $limit > 0 ? $limit : null;
            // publishPending() -> publishBatch() maneja progress internamente
            $publisher->publishPending($limitForPending);
            break;

        default:
            fwrite(STDERR, "ERROR: Acción desconocida: {$action}\n");
            releaseLock();
            exit(1);
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR durante la publicación: " . $e->getMessage() . "\n");
    // En caso de excepción, escribir estado de error directamente al archivo
    $errorData = [
        'status'        => 'error',
        'current_comic' => null,
        'stats'         => [],
        'log'           => [
            ['time' => date('H:i:s'), 'msg' => "❌ Error: " . $e->getMessage(), 'type' => 'error'],
        ],
        'timestamp'     => time(),
        'ended'         => date('c'),
    ];
    @file_put_contents(PUBLISH_PROGRESS_FILE, json_encode($errorData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    $exitCode = 1;
}

// ──────────────────────────────────────────────────────────────
// 8. LIMPIEZA FINAL
// ──────────────────────────────────────────────────────────────

releaseLock();
exit($exitCode);
