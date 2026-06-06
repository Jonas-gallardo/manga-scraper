<?php
/**
 * save_config.php
 *
 * API AJAX para guardar/leer configuración desde la interfaz.
 * Los datos se almacenan en config.json.
 */

header('Content-Type: application/json; charset=utf-8');

$config_file = __DIR__ . '/config.json';

// ── Modo GET: leer configuración actual ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($config_file)) {
        $config = json_decode(file_get_contents($config_file), true);
        // No mostrar la contraseña completa por seguridad
        if (isset($config['db_pass']) && $config['db_pass'] !== '') {
            $config['db_pass_hint'] = '••••••••';
        }
        echo json_encode([
            'success' => true,
            'configurado' => true,
            'config' => $config
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => true,
            'configurado' => false,
            'config' => [
                'db_host' => 'localhost',
                'db_name' => 'comics_db',
                'db_user' => 'root',
                'db_pass' => '',
                'site_base_url' => 'https://sitio.com',
                'site_view_path' => '/view',
                'site_batch_path' => '/parody',
                'site_view' => '',
                'site_parody' => '',
                'download_path' => __DIR__ . '/descargas',
                'delay_page_min' => 1.5,
                'delay_page_max' => 3.5,
                'delay_comic_min' => 5,
                'delay_comic_max' => 10,
                'max_retries' => 2,
                'curl_ssl_verify' => false,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── Modo POST: guardar configuración ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host   = trim($_POST['db_host'] ?? 'localhost');
    $db_name   = trim($_POST['db_name'] ?? 'comics_db');
    $db_user   = trim($_POST['db_user'] ?? 'root');
    $db_pass       = $_POST['db_pass'] ?? '';
    $site_url        = trim($_POST['site_base_url'] ?? 'https://sitio.com');
    $site_view_path  = '/' . ltrim(trim($_POST['site_view_path'] ?? '/view'), '/');
    $site_batch_path = '/' . ltrim(trim($_POST['site_batch_path'] ?? '/parody'), '/');
    $download_path   = trim($_POST['download_path'] ?? '');

    // Validar que la URL del sitio sea válida
    if (!preg_match('#^https?://[^/]+#', $site_url)) {
        echo json_encode(['success' => false, 'message' => 'La URL del sitio no es válida']);
        exit;
    }

    // Si ya existe config, mantener la contraseña anterior si no se envió una nueva
    if ($db_pass === '' && file_exists($config_file)) {
        $existing = json_decode(file_get_contents($config_file), true);
        if (isset($existing['db_pass']) && $existing['db_pass'] !== '') {
            $db_pass = $existing['db_pass'];
        }
    }

    // Validar download_path (si se proporciona)
    if ($download_path !== '') {
        // Si es relativo, convertirlo a absoluto respecto a __DIR__
        if (strpos($download_path, '/') !== 0) {
            $download_path = __DIR__ . '/' . ltrim($download_path, '/');
        }
        // Intentar crearlo si no existe
        if (!is_dir($download_path)) {
            if (!@mkdir($download_path, 0777, true)) {
                echo json_encode(['success' => false, 'message' => 'No se pudo crear el directorio de descargas: ' . $download_path]);
                exit;
            }
        }
        // Verificar que sea escribible
        if (!is_writable($download_path)) {
            echo json_encode(['success' => false, 'message' => 'El directorio de descargas no tiene permisos de escritura: ' . $download_path]);
            exit;
        }
    }

    // Probar conexión a la base de datos
    try {
        $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
        $test_pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error de conexión a la base de datos: ' . $e->getMessage()
        ]);
        exit;
    }

    // Construir URLs derivadas desde los paths configurados
    $site_view   = rtrim($site_url, '/') . '/' . ltrim($site_view_path, '/');
    $site_parody = rtrim($site_url, '/') . '/' . ltrim($site_batch_path, '/');
    $site_domain = parse_url($site_url, PHP_URL_HOST);

    // Guardar configuración
    $config = [
        'db_host'         => $db_host,
        'db_name'         => $db_name,
        'db_user'         => $db_user,
        'db_pass'         => $db_pass,
        'site_base_url'   => rtrim($site_url, '/'),
        'site_view_path'  => $site_view_path,
        'site_batch_path' => $site_batch_path,
        'site_view'       => $site_view,
        'site_parody'     => $site_parody,
        'site_domain'     => $site_domain,
        'download_path'   => $download_path !== '' ? $download_path : (__DIR__ . '/descargas'),
        'curl_ssl_verify' => isset($_POST['curl_ssl_verify']) ? (bool) $_POST['curl_ssl_verify'] : false,
        'delay_page_min'  => (float) ($_POST['delay_page_min'] ?? 1.5),
        'delay_page_max'  => (float) ($_POST['delay_page_max'] ?? 3.5),
        'delay_comic_min' => (int) ($_POST['delay_comic_min'] ?? 5),
        'delay_comic_max' => (int) ($_POST['delay_comic_max'] ?? 10),
        'max_retries'     => (int) ($_POST['max_retries'] ?? 2),
        'saved_at'        => date('Y-m-d H:i:s'),
    ];

    $written = file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($written === false) {
        echo json_encode(['success' => false, 'message' => 'No se pudo escribir el archivo de configuración']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Configuración guardada y conexión verificada exitosamente.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Método no permitido']);
