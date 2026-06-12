<?php
/**
 * wp-media-bridge.php
 *
 * Bridge para subida de imágenes vía JSON base64.
 * Evade la regla @validateByteRange 1-255 del WAF Imunify360/ModSecurity
 * que bloquea el contenido binario de imágenes en POST directos.
 *
 * Recibe: JSON POST { "filename": "...", "base64_data": "...", "mime_type": "...", "alt_text": "..." }
 * Responde: JSON { "id": 12345, "url": "https://..." } o { "error": "..." }
 *
 * Seguridad: usa el mismo Basic Auth (Application Passwords) de WordPress.
 * Solo usuarios con capacidad 'upload_files' pueden subir.
 *
 * INSTALACIÓN:
 * 1. Colocar este archivo en la raíz de WordPress (junto a wp-config.php).
 * 2. Añadir al .htaccess de WordPress (ANTES de las reglas de WordPress):
 *
 *    <IfModule mod_rewrite.c>
 *    RewriteEngine On
 *    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
 *    </IfModule>
 *
 * @package ComicScraperPro
 */

// ── 1. Cargar WordPress ──
$wpLoad = __DIR__ . '/wp-load.php';
if (!file_exists($wpLoad)) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'wp-load.php not found. Place this file in WordPress root.']));
}
require_once $wpLoad;

// ── Cargar funciones de administración requeridas por media_handle_sideload() ──
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// ── 1.5 Modo diagnóstico: ?test=1 muestra headers disponibles para debug ──
if (isset($_GET['test'])) {
    header('Content-Type: application/json');
    $testInfo = [
        'php_sapi'          => php_sapi_name(),
        'PHP_AUTH_USER'     => $_SERVER['PHP_AUTH_USER'] ?? '(no definido)',
        'PHP_AUTH_PW'       => isset($_SERVER['PHP_AUTH_PW']) ? '(presente, ' . strlen($_SERVER['PHP_AUTH_PW']) . ' chars)' : '(no definido)',
        'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? '(no definido)',
        'REDIRECT_HTTP_AUTHORIZATION' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '(no definido)',
        'apache_request_headers_available' => function_exists('apache_request_headers'),
        'all_auth_headers'  => [],
    ];

    foreach ($_SERVER as $k => $v) {
        if (preg_match('/^(HTTP_|AUTH|REDIRECT_HTTP_)/i', $k)) {
            $testInfo['all_auth_headers'][$k] = is_string($v) ? substr($v, 0, 120) : $v;
        }
    }

    if (function_exists('apache_request_headers')) {
        $testInfo['apache_headers'] = apache_request_headers();
    }

    die(json_encode($testInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ── 2. Extraer credenciales de múltiples fuentes ──
$authUser = '';
$authPw   = '';

// Fuente 1: PHP_AUTH_*
$phpAuthUser = $_SERVER['PHP_AUTH_USER'] ?? '';
$phpAuthPw   = $_SERVER['PHP_AUTH_PW']   ?? '';
if (!empty($phpAuthUser)) {
    $authUser = $phpAuthUser;
    $authPw   = $phpAuthPw;
}

// Fuente 2: HTTP_AUTHORIZATION
if (empty($authUser)) {
    $rawAuth = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
    if (!empty($rawAuth) && preg_match('/^Basic\s+(.+)$/i', $rawAuth, $m)) {
        $decoded = base64_decode($m[1], true);
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            [$authUser, $authPw] = explode(':', $decoded, 2);
        }
    }
}

// Fuente 3: apache_request_headers()
if (empty($authUser) && function_exists('apache_request_headers')) {
    $reqHeaders = apache_request_headers();
    $rawAuth = $reqHeaders['Authorization'] ?? $reqHeaders['authorization'] ?? '';
    if (!empty($rawAuth) && preg_match('/^Basic\s+(.+)$/i', $rawAuth, $m)) {
        $decoded = base64_decode($m[1], true);
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            [$authUser, $authPw] = explode(':', $decoded, 2);
        }
    }
}

// Fuente 4: getallheaders()
if (empty($authUser) && function_exists('getallheaders')) {
    $allHeaders = getallheaders();
    $rawAuth = $allHeaders['Authorization'] ?? $allHeaders['authorization'] ?? '';
    if (!empty($rawAuth) && preg_match('/^Basic\s+(.+)$/i', $rawAuth, $m)) {
        $decoded = base64_decode($m[1], true);
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            [$authUser, $authPw] = explode(':', $decoded, 2);
        }
    }
}

// ── 3. Autenticación robusta en cascada ──
$userId = 0;
$authenticated = false;
$debugAuth = [];

// Método 1: wp_authenticate_application_password (nativo WP 5.6+)
$user = wp_authenticate_application_password(null, $authUser, $authPw);
if ($user instanceof WP_User && !empty($user->ID)) {
    $userId = (int) $user->ID;
    $authenticated = true;
    $debugAuth['method1'] = 'success (ID=' . $userId . ')';
} else {
    $debugAuth['method1'] = is_wp_error($user) ? 'WP_Error: ' . $user->get_error_message() : (is_object($user) ? get_class($user) . ' (empty ID)' : 'returned null');
}

// Método 2: wp_authenticate (Application Passwords pass-through)
if (!$authenticated && !empty($authUser) && !empty($authPw)) {
    $user2 = wp_authenticate($authUser, $authPw);
    if ($user2 instanceof WP_User && !empty($user2->ID)) {
        $userId = (int) $user2->ID;
        $authenticated = true;
        $debugAuth['method2'] = 'success (ID=' . $userId . ')';
    } else {
        $debugAuth['method2'] = is_wp_error($user2) ? 'WP_Error: ' . $user2->get_error_message() : 'returned null/empty';
    }
}

// Método 3: Validación manual directa
if (!$authenticated && !empty($authUser) && !empty($authPw)) {
    $foundUser = get_user_by('login', $authUser);
    if ($foundUser instanceof WP_User) {
        // Validar Application Password manualmente
        $appPasswords = WP_Application_Passwords::get_user_application_passwords($foundUser->ID);
        foreach ($appPasswords as $app) {
            if (wp_check_password($authPw, $app['password'])) {
                $userId = $foundUser->ID;
                $authenticated = true;
                $debugAuth['method3'] = 'success via application password lookup (ID=' . $userId . ')';
                break;
            }
        }
        // Fallback: validar como contraseña normal
        if (!$authenticated) {
            require_once ABSPATH . WPINC . '/class-phpass.php';
            $hasher = new PasswordHash(8, true);
            if ($hasher->CheckPassword($authPw, $foundUser->data->user_pass)) {
                $userId = $foundUser->ID;
                $authenticated = true;
                $debugAuth['method3'] = 'success via regular password (ID=' . $userId . ')';
            }
        }
    }
    if (!$authenticated) {
        $debugAuth['method3'] = 'failed (user found: ' . ($foundUser ? 'yes' : 'no') . ')';
    }
}

if (!$authenticated) {
    http_response_code(401);
    header('Content-Type: application/json');
    die(json_encode([
        'error' => 'Unauthorized',
        '_debug' => array_merge([
            'authUser' => $authUser ?: '(empty)',
            'authPw_len' => strlen($authPw),
        ], $debugAuth),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ── 4. Cargar usuario completo y verificar capabilities ──
$fullUser = new WP_User($userId);
wp_set_current_user($userId);

if (!user_can($fullUser, 'upload_files')) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode([
        'error' => 'Forbidden',
        '_debug' => [
            'reason' => 'Authenticated but lacks upload_files capability',
            'user_id' => $userId,
            'user_login' => $fullUser->user_login,
            'roles' => $fullUser->roles,
            'allcaps_has_upload_files' => !empty($fullUser->allcaps['upload_files']),
        ],
    ], JSON_UNESCAPED_UNICODE));
}

// ── 4.5 Acción create_post: publicar un post vía bridge ──
// Evita el problema de que LiteSpeed/CGI borra el header Authorization
// en peticiones a /wp-json/wp/v2/posts.
$action = $_GET['action'] ?? ($_SERVER['HTTP_X_BRIDGE_ACTION'] ?? '');

if ($action === 'create_post') {
    $raw = file_get_contents('php://input');
    $postData = json_decode($raw, true);

    if (!is_array($postData) || empty($postData['title'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Missing required field: title']));
    }

    // ── Construir post ──
    $postArr = [
        'post_title'   => wp_strip_all_tags($postData['title']),
        'post_status'  => $postData['status'] ?? 'publish',
        'post_type'    => $postData['post_type'] ?? 'post',
        'post_author'  => $fullUser->ID,
    ];

    // Contenido opcional
    if (!empty($postData['content'])) {
        $postArr['post_content'] = $postData['content'];
    }

    // Featured image
    if (!empty($postData['featured_media'])) {
        $postArr['meta_input']['_thumbnail_id'] = (int) $postData['featured_media'];
    }

    // Insertar post
    $postId = wp_insert_post($postArr, true);

    if (is_wp_error($postId)) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['error' => $postId->get_error_message()]));
    }

    // ── Taxonomías: tags ──
    if (!empty($postData['tags']) && is_array($postData['tags'])) {
        wp_set_post_terms($postId, array_map('intval', $postData['tags']), 'post_tag', false);
    }

    // ── Taxonomías personalizadas ──
    $customTaxonomies = ['universo', 'personaje', 'idioma', 'tipo'];
    foreach ($customTaxonomies as $tax) {
        if (!empty($postData[$tax])) {
            $terms = is_array($postData[$tax]) ? $postData[$tax] : [$postData[$tax]];
            wp_set_post_terms($postId, array_map('intval', $terms), $tax, false);
        }
    }

    // ── ACF Meta Fields ──
    if (!empty($postData['acf']) && is_array($postData['acf'])) {
        foreach ($postData['acf'] as $key => $value) {
            update_post_meta($postId, $key, $value);
            // También guardar la referencia del field key si se proporciona
            if (isset($postData['_acf_keys'][$key])) {
                update_post_meta($postId, '_' . $key, $postData['_acf_keys'][$key]);
            }
        }
    }

    // ── Responder ──
    $postUrl = get_permalink($postId);
    header('Content-Type: application/json');
    echo json_encode([
        'id'  => $postId,
        'url' => $postUrl ?: '',
        'title' => $postArr['post_title'],
        'status' => $postArr['post_status'],
        'type' => $postArr['post_type'],
    ]);
    exit;
}

// ── 5. Leer JSON del cuerpo (subida de imágenes) ──
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input) || empty($input['filename']) || empty($input['base64_data'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Missing required fields: filename, base64_data']));
}

$filename   = sanitize_file_name($input['filename']);
$base64Data = $input['base64_data'];
$mimeType   = $input['mime_type'] ?? '';
$altText    = $input['alt_text'] ?? '';

// ── 6. Decodificar base64 a archivo temporal ──
$decoded = base64_decode($base64Data, true);
if ($decoded === false) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Invalid base64 data']));
}

$ext = pathinfo($filename, PATHINFO_EXTENSION);
$ext = $ext ?: 'webp';
$tmpFile = tempnam(sys_get_temp_dir(), 'wpbridge_');
$tmpWithExt = $tmpFile . '.' . $ext;
rename($tmpFile, $tmpWithExt);
$tmpFile = $tmpWithExt;

file_put_contents($tmpFile, $decoded);

// ── 7. Preparar array para media_handle_sideload ──
$fileArray = [
    'name'     => $filename,
    'tmp_name' => $tmpFile,
    'type'     => $mimeType ?: mime_content_type($tmpFile),
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmpFile),
];

// ── 8. Crear attachment ──
$attachmentId = media_handle_sideload($fileArray, 0, $altText);

// ── 9. Limpiar archivo temporal ──
if (file_exists($tmpFile)) {
    @unlink($tmpFile);
}

if (is_wp_error($attachmentId)) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['error' => $attachmentId->get_error_message()]));
}

// ── 10. Responder ──
$url = wp_get_attachment_url($attachmentId);

header('Content-Type: application/json');
echo json_encode([
    'id'  => $attachmentId,
    'url' => $url ?: '',
]);
