<?php
/**
 * publish_to_wp.php
 *
 * Módulo de subida y publicación automática por lotes a WordPress (Gluglux).
 *
 * FLUJO:
 *   PASO A: Sube imágenes .webp a /wp/v2/media → colecciona IDs
 *   PASO B: Formatea IDs como string separado por comas para photo_gallery
 *   PASO C: Publica el post en /wp/v2/posts con taxonomías sincronizadas
 *
 * MODOS:
 *   ?action=single&comic_id=123    → Publica un cómic individual
 *   ?action=batch&limit=10         → Publica los siguientes N pendientes
 *   ?action=pending                → Publica TODOS los pendientes
 *   ?action=status                 → Devuelve el estado de publicación
 *   (Sin parámetros)               → Muestra la interfaz web
 *
 * @package ScrapApp
 */

// ──────────────────────────────────────────────────────────────
// 0. CONFIGURACIÓN INICIAL
// ──────────────────────────────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(300); // 5 minutos máximo por lote
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/src/WP/WPClient.php';
require_once __DIR__ . '/src/WP/WPTaxonomySync.php';
require_once __DIR__ . '/src/WP/WPPublisher.php';

// ──────────────────────────────────────────────────────────────
// 1. DETECTAR MODO
// ──────────────────────────────────────────────────────────────

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
$comicId = isset($_GET['comic_id']) ? (int) $_GET['comic_id'] : (isset($_POST['comic_id']) ? (int) $_POST['comic_id'] : 0);
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : (isset($_POST['limit']) ? (int) $_POST['limit'] : 0);

// ── Si no hay acción, mostrar la interfaz web ──
if (empty($action)) {
    renderWebUI();
    exit;
}

// ── Para acciones API, configurar salida JSON ──
$isAjax = ($action !== 'webui');
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
}

// ──────────────────────────────────────────────────────────────
// 2. INICIALIZAR COMPONENTES WP
// ──────────────────────────────────────────────────────────────

try {
    $wpConfig = getWPConfig();
    if ($wpConfig === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Configuración de WordPress no encontrada. Configure las credenciales en la sección de configuración.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $client = new WPClient(
        $wpConfig['base_url'],
        $wpConfig['username'],
        $wpConfig['password'],
        ['timeout' => 60, 'max_retries' => 3]
    );

    $taxonomySync = new WPTaxonomySync($client);
    $publisher = new WPPublisher($client, $taxonomySync, $pdo);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error inicializando módulo WordPress: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 3. EJECUTAR ACCIÓN
// ──────────────────────────────────────────────────────────────

try {
    switch ($action) {
        case 'single':
            handleSingle($publisher, $comicId);
            break;

        case 'batch':
            handleBatch($publisher, $limit);
            break;

        case 'pending':
            handlePending($publisher, $limit);
            break;

        case 'status':
            handleStatus($pdo);
            break;

        case 'reprocess':
            handleReprocess($pdo, $publisher, $comicId);
            break;

        case 'reset':
            handleReset($pdo);
            break;

        case 'delete':
            handleDelete($pdo);
            break;

        case 'stop':
            handleStop();
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida. Use: single, batch, pending, status, reprocess, reset, delete, stop',
            ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error en la operación: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}


// ════════════════════════════════════════════════════════════════
//  FUNCIONES DE MANEJO DE ACCIONES
// ════════════════════════════════════════════════════════════════

/**
 * Maneja la publicación de un cómic individual.
 */
function handleSingle(WPPublisher $publisher, int $comicId): void
{
    if ($comicId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Se requiere un comic_id válido',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $result = $publisher->publishComic($comicId);
    echo json_encode([
        'success' => $result['success'] ?? false,
        'message' => $result['success']
            ? "✅ «{$result['titulo']}» publicado (WP Post ID: {$result['wp_post_id']}, imágenes: {$result['images_uploaded']})"
            : "❌ Error: " . ($result['error'] ?? 'Desconocido'),
        'data'    => $result,
        'stats'   => $publisher->getStats(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Maneja la publicación por lote.
 */
function handleBatch(WPPublisher $publisher, int $limit): void
{
    $limit = $limit > 0 ? $limit : 10;

    $results = $publisher->publishPending($limit, function ($progress) {
        // En AJAX normal, no podemos hacer streaming línea por línea
        // Pero para respuestas síncronas, solo acumulamos resultados
    });

    echo json_encode([
        'success' => true,
        'message' => "Lote completado: {$results['published']} publicados, {$results['errors']} errores, {$results['skipped']} omitidos",
        'data'    => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Maneja la publicación de todos los pendientes.
 */
function handlePending(WPPublisher $publisher, int $limit): void
{
    $limit = $limit > 0 ? $limit : null; // null = todos

    $results = $publisher->publishPending($limit);

    echo json_encode([
        'success' => true,
        'message' => "Proceso completado: {$results['published']} publicados, {$results['errors']} errores, {$results['skipped']} omitidos de {$results['total_comics']} totales",
        'data'    => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Devuelve el estado de publicación actual.
 */
function handleStatus(PDO $pdo): void
{
    try {
        // Asegurar que las columnas existan
        $stmt = $pdo->query("SHOW COLUMNS FROM comics_descargados LIKE 'wp_publish_status'");
        $hasColumns = (bool) $stmt->fetch();

        $status = [
            'total_comics'    => 0,
            'published'       => 0,
            'pending'         => 0,
            'error'           => 0,
            'publishing'      => 0,
            'no_status'       => 0,
            'columns_exist'   => $hasColumns,
        ];

        if ($hasColumns) {
            $stmt = $pdo->query(
                "SELECT wp_publish_status, COUNT(*) as cantidad
                 FROM comics_descargados
                 GROUP BY wp_publish_status"
            );
            while ($row = $stmt->fetch()) {
                $s = $row['wp_publish_status'] ?? 'no_status';
                if ($s === '' || $s === null) {
                    $s = 'no_status';
                }
                $status[$s] = (int) $row['cantidad'];
            }

            // Total
            $status['total_comics'] = array_sum([
                $status['published'],
                $status['pending'],
                $status['error'],
                $status['publishing'],
                $status['no_status'],
            ]);

            // Total de cómics completos pendientes
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM comics_descargados
                 WHERE (wp_publish_status IS NULL OR wp_publish_status IN ('pending', 'error'))
                   AND estado = 'completo'"
            );
            $status['publishable'] = (int) $stmt->fetchColumn();

            // Últimas publicaciones
            $stmt = $pdo->query(
                "SELECT id_fuente, titulo, wp_post_id, wp_publish_status, wp_publish_error
                 FROM comics_descargados
                 WHERE wp_publish_status IS NOT NULL
                 ORDER BY id_fuente DESC
                 LIMIT 10"
            );
            $status['recent'] = $stmt->fetchAll();
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM comics_descargados");
            $status['total_comics'] = (int) $stmt->fetchColumn();
            $status['message'] = 'Columnas de publicación no creadas aún. Ejecute una publicación para crearlas.';
        }

        echo json_encode([
            'success' => true,
            'data'    => $status,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error obteniendo estado: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Re-procesa un cómic (resetea estado y vuelve a publicar).
 */
function handleReprocess(PDO $pdo, WPPublisher $publisher, int $comicId): void
{
    if ($comicId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Se requiere un comic_id válido',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Resetear estado
    try {
        $stmt = $pdo->prepare(
            "UPDATE comics_descargados
             SET wp_publish_status = 'pending', wp_post_id = NULL, wp_publish_error = NULL
             WHERE id_fuente = ?"
        );
        $stmt->execute([$comicId]);
    } catch (Exception $e) {
        // Ignorar si las columnas no existen
    }

    $result = $publisher->publishComic($comicId);
    echo json_encode([
        'success' => $result['success'] ?? false,
        'message' => $result['success']
            ? "✅ Re-publicado: «{$result['titulo']}» (WP Post ID: {$result['wp_post_id']})"
            : "❌ Error: " . ($result['error'] ?? 'Desconocido'),
        'data'    => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Resetea el estado de publicación de todos los cómics publicados
 * (vuelve a 'pending' para poder re-publicarlos).
 */
function handleReset(PDO $pdo): void
{
    try {
        // Verificar que las columnas existan
        $stmt = $pdo->query("SHOW COLUMNS FROM comics_descargados LIKE 'wp_publish_status'");
        if (!$stmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'No hay columnas de publicación que resetear.',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $pdo->exec(
            "UPDATE comics_descargados
             SET wp_publish_status = 'pending', wp_post_id = NULL, wp_publish_error = NULL
             WHERE wp_publish_status = 'published'
                OR wp_publish_status = 'error'"
        );

        echo json_encode([
            'success' => true,
            'message' => "✅ Se resetearon {$stmt} cómics a estado 'pending'.",
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error reseteando publicaciones: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Elimina completamente los registros de seguimiento de publicación
 * (wp_publish_status, wp_post_id, wp_publish_error se ponen a NULL).
 */
function handleDelete(PDO $pdo): void
{
    try {
        // Verificar que las columnas existan
        $stmt = $pdo->query("SHOW COLUMNS FROM comics_descargados LIKE 'wp_publish_status'");
        if (!$stmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'No hay columnas de publicación que limpiar.',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $pdo->exec(
            "UPDATE comics_descargados
             SET wp_publish_status = NULL, wp_post_id = NULL, wp_publish_error = NULL
             WHERE wp_publish_status IS NOT NULL"
        );

        echo json_encode([
            'success' => true,
            'message' => "🗑️ Se limpiaron {$stmt} registros de publicación.",
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error limpiando registros: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Detiene la publicación en curso creando la señal de stop.
 */
function handleStop(): void
{
    $written = @file_put_contents(PUBLISH_STOP_FILE, '1', LOCK_EX);
    if ($written !== false) {
        echo json_encode([
            'success' => true,
            'message' => '⏹ Señal de detención enviada. La publicación se detendrá en breve.',
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $touched = @touch(PUBLISH_STOP_FILE);
        if ($touched) {
            echo json_encode([
                'success' => true,
                'message' => '⏹ Señal de detención enviada.',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No se pudo crear la señal de detención.',
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}


// ════════════════════════════════════════════════════════════════
//  FUNCIONES AUXILIARES
// ════════════════════════════════════════════════════════════════

/**
 * Obtiene la configuración de WordPress.
 * Primero busca en config.json (configurable desde UI),
 * si no, usa valores por defecto definidos aquí.
 *
 * @return array<string, string>|null
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


// ════════════════════════════════════════════════════════════════
//  INTERFAZ WEB
// ════════════════════════════════════════════════════════════════

/**
 * Renderiza la interfaz web de publicación.
 */
function renderWebUI(): void
{
    global $pdo;

    // Obtener estadísticas para mostrar
    $stats = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM comics_descargados LIKE 'wp_publish_status'");
        if ($stmt->fetch()) {
            $stmt = $pdo->query(
                "SELECT wp_publish_status, COUNT(*) as cantidad
                 FROM comics_descargados GROUP BY wp_publish_status"
            );
            while ($row = $stmt->fetch()) {
                $s = $row['wp_publish_status'] ?? 'sin estado';
                if ($s === '' || $s === null) $s = 'sin estado';
                $stats[$s] = (int) $row['cantidad'];
            }

            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM comics_descargados
                 WHERE (wp_publish_status IS NULL OR wp_publish_status IN ('pending', 'error'))
                   AND estado = 'completo'"
            );
            $stats['publishable'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query(
                "SELECT id_fuente, titulo, wp_post_id, wp_publish_status
                 FROM comics_descargados
                 WHERE wp_publish_status = 'published'
                 ORDER BY id_fuente DESC LIMIT 5"
            );
            $stats['recently_published'] = $stmt->fetchAll();
        }

        $stmt = $pdo->query("SELECT COUNT(*) FROM comics_descargados");
        $stats['total'] = (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        $stats['error'] = $e->getMessage();
    }

    $published = (int) ($stats['published'] ?? 0);
    $pending   = (int) ($stats['pending'] ?? 0);
    $error     = (int) ($stats['error'] ?? 0);
    $publishable = (int) ($stats['publishable'] ?? 0);
    $total     = (int) ($stats['total'] ?? 0);
    $recent    = $stats['recently_published'] ?? [];

    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Publicar a WordPress — Gluglux</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
        <style>
            *, *::before, *::after { box-sizing: border-box; }
            body {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                background: #080b12;
                color: #e2e8f0;
                min-height: 100vh;
            }
            .bg-app {
                position: fixed; inset: 0; z-index: -1;
                background:
                    radial-gradient(ellipse 80% 60% at 50% -20%, rgba(99,102,241,0.12) 0%, transparent 60%),
                    radial-gradient(ellipse 40% 50% at 80% 80%, rgba(168,85,247,0.08) 0%, transparent 50%),
                    radial-gradient(ellipse 30% 40% at 20% 60%, rgba(59,130,246,0.06) 0%, transparent 50%),
                    #080b12;
            }
            .glass {
                background: rgba(22, 27, 34, 0.75);
                backdrop-filter: blur(12px);
                border: 1px solid rgba(48, 54, 61, 0.5);
                border-radius: 1rem;
                transition: all 0.25s ease;
            }
            .glass:hover { border-color: rgba(99, 102, 241, 0.25); }
            .glass-strong {
                background: rgba(13, 17, 23, 0.85);
                backdrop-filter: blur(16px);
                border: 1px solid rgba(48, 54, 61, 0.6);
                border-radius: 1rem;
            }
            .glass-input {
                background: rgba(13, 17, 23, 0.7);
                border: 1px solid rgba(48, 54, 61, 0.6);
                border-radius: 0.75rem;
                color: #e2e8f0;
                transition: all 0.2s ease;
                font-size: 0.9rem;
            }
            .glass-input:focus {
                outline: none;
                border-color: #6366f1;
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            }
            .glass-input::placeholder { color: #4a5568; }
            .btn-glow {
                padding: 0.625rem 1.5rem;
                border-radius: 0.75rem;
                font-weight: 600;
                font-size: 0.875rem;
                color: white;
                background: linear-gradient(135deg, #6366f1, #8b5cf6);
                border: none;
                cursor: pointer;
                transition: all 0.25s ease;
            }
            .btn-glow:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(99, 102, 241, 0.35); }
            .btn-glow:disabled { opacity: 0.5; cursor: not-allowed; }
            .btn-danger {
                padding: 0.625rem 1.5rem;
                border-radius: 0.75rem;
                font-weight: 600;
                font-size: 0.875rem;
                color: white;
                background: linear-gradient(135deg, #ef4444, #dc2626);
                border: none;
                cursor: pointer;
                transition: all 0.25s ease;
            }
            .btn-danger:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239, 68, 68, 0.35); }
            .btn-ghost {
                padding: 0.625rem 1.25rem;
                border-radius: 0.75rem;
                font-weight: 500;
                color: #94a3b8;
                background: rgba(30, 35, 45, 0.6);
                border: 1px solid rgba(48, 54, 61, 0.4);
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                transition: all 0.2s;
            }
            .btn-ghost:hover { background: rgba(40, 46, 56, 0.8); color: #e2e8f0; }
            .stat-value { font-size: 1.75rem; font-weight: 800; margin-top: 0.25rem; }
            #log-container {
                background: #0a0d14;
                border: 1px solid rgba(48, 54, 61, 0.3);
                border-radius: 0.75rem;
                padding: 1rem;
                font-family: 'JetBrains Mono', monospace;
                font-size: 0.75rem;
                line-height: 1.6;
                max-height: 400px;
                overflow-y: auto;
                white-space: pre-wrap;
                word-break: break-all;
            }
            #log-container .log-success { color: #4ade80; }
            #log-container .log-error { color: #f87171; }
            #log-container .log-info { color: #60a5fa; }
            #log-container .log-warning { color: #fbbf24; }
        </style>
    </head>
    <body class="p-6">
        <div class="bg-app"></div>

        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-white">📤 Publicar a WordPress</h1>
                    <p class="text-gray-500 text-sm mt-1">Gluglux — Publicación automática por lotes</p>
                </div>
                <div class="flex gap-2">
                    <a href="index.php" class="btn-ghost text-sm">⬅ Volver</a>
                    <button onclick="refreshStatus()" class="btn-ghost text-sm">🔄 Actualizar</button>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <div class="glass p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Total Cómics</p>
                    <p class="stat-value text-indigo-400"><?= $total ?></p>
                </div>
                <div class="glass p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Publicados</p>
                    <p class="stat-value text-emerald-400"><?= $published ?></p>
                </div>
                <div class="glass p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Pendientes</p>
                    <p class="stat-value text-amber-400"><?= $pending ?></p>
                </div>
                <div class="glass p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Publicables</p>
                    <p class="stat-value text-blue-400"><?= $publishable ?></p>
                </div>
                <div class="glass p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Errores</p>
                    <p class="stat-value text-red-400"><?= $error ?></p>
                </div>
            </div>

            <!-- Actions -->
            <div class="glass p-5 mb-6">
                <h3 class="text-sm font-semibold text-white mb-4">🚀 Acciones de Publicación</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Publicar individual -->
                    <div class="glass-strong p-4">
                        <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Publicar Individual</h4>
                        <div class="flex gap-2">
                            <input type="number" id="single-comic-id"
                                   class="glass-input w-28 px-3 py-2 text-sm"
                                   placeholder="ID del cómic" min="1">
                            <button onclick="publishSingle()" class="btn-glow text-xs px-4 py-2">
                                ▶ Publicar
                            </button>
                        </div>
                    </div>

                    <!-- Publicar lote -->
                    <div class="glass-strong p-4">
                        <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Publicar Lote</h4>
                        <div class="flex gap-2">
                            <input type="number" id="batch-limit"
                                   class="glass-input w-24 px-3 py-2 text-sm"
                                   placeholder="Cantidad" value="10" min="1" max="100">
                            <button onclick="publishBatch()" class="btn-glow text-xs px-4 py-2">
                                ▶ Publicar Lote
                            </button>
                        </div>
                    </div>

                    <!-- Publicar todos los pendientes -->
                    <div class="glass-strong p-4">
                        <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Publicar Todos</h4>
                        <button onclick="publishAll()" class="btn-glow text-sm w-full"
                                <?= $publishable === 0 ? 'disabled' : '' ?>>
                            📤 Publicar <?= $publishable ?> Pendientes
                        </button>
                    </div>
                </div>

                <!-- Botones de Control: Detener, Reset, Delete -->
                <div class="flex flex-wrap gap-3 mt-4 pt-4 border-t border-gray-800">
                    <button onclick="stopPublish()" id="btn-stop"
                            class="btn-danger text-xs px-4 py-2 flex items-center gap-1.5">
                        ⏹ Detener
                    </button>
                    <button onclick="resetPublished()" id="btn-reset"
                            class="btn-ghost text-xs px-4 py-2 flex items-center gap-1.5">
                        🔄 Resetear Publicados
                    </button>
                    <button onclick="deleteRecords()" id="btn-delete"
                            class="btn-ghost text-xs px-4 py-2 flex items-center gap-1.5"
                            style="border-color: rgba(239,68,68,0.3); color: #fca5a5;">
                        🗑️ Borrar Registros
                    </button>
                    <button onclick="toggleAutoRefresh()" id="btn-autorefresh"
                            class="btn-ghost text-xs px-4 py-2 flex items-center gap-1.5"
                            style="border-color: rgba(99,102,241,0.3); color: #a5b4fc;">
                        ⏸ Pausar Auto-refresh
                    </button>
                </div>
            </div>

            <!-- Log / Output -->
            <div class="glass p-5 mb-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-white">📋 Salida del proceso</h3>
                    <button onclick="clearLog()" class="text-xs text-gray-500 hover:text-gray-300">Limpiar</button>
                </div>
                <div id="log-container">
                    <span class="log-info">Listo. Selecciona una acción para comenzar.</span>
                </div>
            </div>

            <!-- Recently Published -->
            <?php if (!empty($recent)): ?>
            <div class="glass p-5">
                <h3 class="text-sm font-semibold text-white mb-3">🕐 Últimas Publicaciones</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-gray-500 text-xs uppercase tracking-wider">
                                <th class="text-left py-2 pr-4">ID Fuente</th>
                                <th class="text-left py-2 pr-4">Título</th>
                                <th class="text-left py-2 pr-4">WP Post ID</th>
                                <th class="text-left py-2">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $r): ?>
                            <tr class="border-t border-gray-800">
                                <td class="py-2 pr-4 font-mono text-xs"><?= $r['id_fuente'] ?></td>
                                <td class="py-2 pr-4 text-gray-300"><?= htmlspecialchars($r['titulo']) ?></td>
                                <td class="py-2 pr-4 font-mono text-xs text-emerald-400">#<?= $r['wp_post_id'] ?></td>
                                <td class="py-2">
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-900/40 text-emerald-400">
                                        <?= $r['wp_publish_status'] ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        const logContainer = document.getElementById('log-container');

        function log(message, type = 'info') {
            const div = document.createElement('div');
            div.className = 'log-' + type;
            const timestamp = new Date().toLocaleTimeString();
            div.textContent = `[${timestamp}] ${message}`;
            logContainer.appendChild(div);
            logContainer.scrollTop = logContainer.scrollHeight;
        }

        function clearLog() {
            logContainer.innerHTML = '';
            log('Log limpiado.', 'info');
        }

        function setLoading(loading) {
            const buttons = document.querySelectorAll('.btn-glow, .btn-danger');
            buttons.forEach(b => b.disabled = loading);
        }

        async function publishSingle() {
            const comicId = document.getElementById('single-comic-id').value;
            if (!comicId || comicId < 1) {
                log('⚠️ Por favor, ingresa un ID de cómic válido.', 'warning');
                return;
            }

            setLoading(true);
            log(`▶️ Publicando cómic individual ID ${comicId}...`, 'info');

            try {
                const res = await fetch(`publish_to_wp.php?action=single&comic_id=${comicId}`);
                const data = await res.json();

                if (data.success) {
                    log(`✅ ${data.message}`, 'success');
                } else {
                    log(`❌ ${data.message}`, 'error');
                }

                if (data.stats) {
                    log(`📊 Estadísticas: ${data.stats.published} publicados, ${data.stats.errors} errores`, 'info');
                }
            } catch (e) {
                log(`❌ Error de conexión: ${e.message}`, 'error');
            }

            setLoading(false);
            refreshStatus();
        }

        async function publishBatch() {
            const limit = document.getElementById('batch-limit').value || 10;

            setLoading(true);
            log(`▶️ Publicando lote de ${limit} cómics...`, 'info');

            try {
                const res = await fetch(`publish_to_wp.php?action=batch&limit=${limit}`);
                const data = await res.json();

                if (data.success) {
                    log(`✅ ${data.message}`, 'success');
                } else {
                    log(`❌ ${data.message}`, 'error');
                }

                if (data.data) {
                    log(`📊 Publicados: ${data.data.published} | Errores: ${data.data.errors} | Omitidos: ${data.data.skipped}`, 'info');
                }
            } catch (e) {
                log(`❌ Error de conexión: ${e.message}`, 'error');
            }

            setLoading(false);
            refreshStatus();
        }

        async function publishAll() {
            setLoading(true);
            log('▶️ Publicando todos los cómics pendientes...', 'info');

            try {
                const res = await fetch(`publish_to_wp.php?action=pending`);
                const data = await res.json();

                if (data.success) {
                    log(`✅ ${data.message}`, 'success');
                } else {
                    log(`❌ ${data.message}`, 'error');
                }

                if (data.data) {
                    log(`📊 Total: ${data.data.total_comics} | Publicados: ${data.data.published} | Errores: ${data.data.errors} | Omitidos: ${data.data.skipped}`, 'info');
                }
            } catch (e) {
                log(`❌ Error de conexión: ${e.message}`, 'error');
            }

            setLoading(false);
            refreshStatus();
        }

        async function refreshStatus() {
            try {
                const res = await fetch(`publish_to_wp.php?action=status`);
                const data = await res.json();

                if (data.success && data.data) {
                    const s = data.data;
                    // Solo mostrar log si el auto-refresh está activo
                    if (autoRefreshEnabled) {
                        log(`🔄 Estado actualizado: ${s.published} publicados, ${s.publishable ?? 0} publicables, ${s.error} errores`, 'info');
                    }
                }
            } catch (e) {
                // Silencio
            }
        }

        // ── Auto-refresh toggle ──
        let autoRefreshEnabled = true;
        let autoRefreshInterval = setInterval(refreshStatus, 30000);

        function toggleAutoRefresh() {
            const btn = document.getElementById('btn-autorefresh');
            autoRefreshEnabled = !autoRefreshEnabled;

            if (autoRefreshEnabled) {
                autoRefreshInterval = setInterval(refreshStatus, 30000);
                btn.innerHTML = '⏸ Pausar Auto-refresh';
                btn.style.borderColor = 'rgba(99,102,241,0.3)';
                btn.style.color = '#a5b4fc';
                log('🔄 Auto-refresh activado.', 'info');
            } else {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                btn.innerHTML = '▶ Reanudar Auto-refresh';
                btn.style.borderColor = 'rgba(239,68,68,0.3)';
                btn.style.color = '#fca5a5';
                log('⏸ Auto-refresh pausado.', 'info');
            }
        }

        // ── Detener publicación ──
        async function stopPublish() {
            if (!confirm('¿Detener la publicación en curso?')) return;

            try {
                const res = await fetch('stop_publish.php', { method: 'POST' });
                const data = await res.json();

                if (data.success) {
                    log('⏹ ' + data.message, 'warning');
                } else {
                    log('❌ ' + data.message, 'error');
                }
            } catch (e) {
                log(`❌ Error al detener: ${e.message}`, 'error');
            }
        }

        // ── Resetear publicaciones (volver a pending) ──
        async function resetPublished() {
            if (!confirm('¿Resetear todos los cómics publicados a estado "pending"?\nEsto permite re-publicarlos.')) return;

            setLoading(true);
            try {
                const res = await fetch(`publish_to_wp.php?action=reset`);
                const data = await res.json();

                if (data.success) {
                    log('✅ ' + data.message, 'success');
                } else {
                    log('❌ ' + data.message, 'error');
                }
            } catch (e) {
                log(`❌ Error: ${e.message}`, 'error');
            }
            setLoading(false);
            refreshStatus();
        }

        // ── Borrar registros de publicación ──
        async function deleteRecords() {
            if (!confirm('¿Eliminar TODOS los registros de seguimiento de publicación?\nLos cómics volverán a estado "sin publicar".')) return;

            setLoading(true);
            try {
                const res = await fetch(`publish_to_wp.php?action=delete`);
                const data = await res.json();

                if (data.success) {
                    log('✅ ' + data.message, 'success');
                } else {
                    log('❌ ' + data.message, 'error');
                }
            } catch (e) {
                log(`❌ Error: ${e.message}`, 'error');
            }
            setLoading(false);
            refreshStatus();
        }
        </script>
    </body>
    </html>
    <?php
}
