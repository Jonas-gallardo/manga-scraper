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

    // Excerpt opcional (síntesis técnica generada por IA → post_excerpt)
    if (!empty($postData['excerpt'])) {
        $postArr['post_excerpt'] = wp_strip_all_tags($postData['excerpt']);
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
    $customTaxonomies = ['universo', 'personaje', 'idioma', 'tipo', 'autor'];
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

// ── Acción ensure_term: crear/buscar un término de taxonomía vía bridge ──
// Evita que LiteSpeed/CGI borre el header Authorization en peticiones
// a /wp-json/wp/v2/{taxonomy}, igual que create_post evade el problema
// para /wp-json/wp/v2/posts.
if ($action === 'ensure_term') {
    $raw = file_get_contents('php://input');
    $termData = json_decode($raw, true);

    if (!is_array($termData) || empty($termData['taxonomy']) || empty($termData['name'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Missing required fields: taxonomy, name']));
    }

    $taxonomy = sanitize_text_field($termData['taxonomy']);
    $termName = sanitize_text_field($termData['name']);
    $termSlug = !empty($termData['slug']) ? sanitize_title($termData['slug']) : sanitize_title($termName);

    // ── Verificar que la taxonomía existe ──
    if (!taxonomy_exists($taxonomy)) {
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['error' => "Taxonomy '{$taxonomy}' does not exist"]));
    }

    // ── Verificar si el término ya existe ──
    $existing = term_exists($termName, $taxonomy);
    if ($existing !== 0 && $existing !== null) {
        $termId = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
        header('Content-Type: application/json');
        echo json_encode([
            'id'       => $termId,
            'name'     => $termName,
            'slug'     => $termSlug,
            'taxonomy' => $taxonomy,
            'existing' => true,
        ]);
        exit;
    }

    // ── Crear el término ──
    $result = wp_insert_term($termName, $taxonomy, ['slug' => $termSlug]);

    if (is_wp_error($result)) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['error' => $result->get_error_message()]));
    }

    $termId = (int) $result['term_id'];
    header('Content-Type: application/json');
    echo json_encode([
        'id'       => $termId,
        'name'     => $termName,
        'slug'     => $termSlug,
        'taxonomy' => $taxonomy,
        'existing' => false,
    ]);
    exit;
}

// ── Acción set_post_terms: asignar taxonomías a un post existente ──
// Útil para reparar cómics publicados sin taxonomías (por bugs anteriores).
// Recibe: { "post_id": 17709, "taxonomies": { "personaje": [123,456], "autor": [492] } }
if ($action === 'set_post_terms') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input) || empty($input['post_id']) || empty($input['taxonomies'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Missing required fields: post_id, taxonomies']));
    }

    $postId = (int) $input['post_id'];
    $taxonomies = $input['taxonomies'];

    // Verificar que el post existe
    $post = get_post($postId);
    if (!$post) {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(['error' => "Post ID {$postId} not found"]));
    }

    $results = [];
    foreach ($taxonomies as $taxonomy => $termIds) {
        if (!is_array($termIds) || empty($termIds)) {
            continue;
        }

        if (!taxonomy_exists($taxonomy)) {
            $results[$taxonomy] = ['error' => "Taxonomy '{$taxonomy}' does not exist"];
            continue;
        }

        $intIds = array_map('intval', $termIds);
        $result = wp_set_post_terms($postId, $intIds, $taxonomy, false);

        if (is_wp_error($result)) {
            $results[$taxonomy] = ['error' => $result->get_error_message()];
        } elseif (is_array($result)) {
            $results[$taxonomy] = ['assigned' => count($result), 'ids' => $result];
        } else {
            $results[$taxonomy] = ['assigned' => 0];
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'    => true,
        'post_id'    => $postId,
        'results'    => $results,
    ]);
    exit;
}

// ── Acción list_posts: listar posts publicados con sus taxonomías ──
// Usada por la Fase 1 (compensación) para consultar los posts DESDE el sitio
// (no la BD local), obtener su ID real y los nombres de términos de cada taxonomía.
// Recibe (JSON opcional): { "post_type": "post", "per_page": 20, "page": 1, "offset": 0 }
// Responde: { "success": true, "count": N, "posts": [ { "id", "title", "excerpt", "taxonomies": {...} } ] }
if ($action === 'list_posts') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = [];
    }

    $postType = sanitize_text_field($input['post_type'] ?? 'post');
    $perPage  = max(1, min(100, (int) ($input['per_page'] ?? 20)));
    $offset   = max(0, (int) ($input['offset'] ?? 0));

    if (!user_can($fullUser, 'edit_posts')) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Forbidden', 'reason' => 'requires edit_posts capability']));
    }

    if (!post_type_exists($postType)) {
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['error' => "Post type '{$postType}' does not exist"]));
    }

    $taxonomies = ['post_tag', 'universo', 'personaje', 'idioma', 'tipo', 'autor'];

    $queryArgs = [
        'post_type'      => $postType,
        'post_status'    => 'publish',
        'posts_per_page' => $perPage,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ];

    $posts = get_posts($queryArgs);

    $items = [];
    foreach ($posts as $p) {
        $item = [
            'id'      => (int) $p->ID,
            'title'   => $p->post_title,
            'excerpt' => (string) ($p->post_excerpt ?? ''),
            'taxonomies' => [],
        ];

        foreach ($taxonomies as $tax) {
            if (!taxonomy_exists($tax)) {
                continue;
            }
            $terms = wp_get_post_terms($p->ID, $tax, ['fields' => 'names']);
            if (is_wp_error($terms)) {
                $terms = [];
            }
            $terms = array_values(array_map('strval', $terms));
            if (!empty($terms)) {
                $item['taxonomies'][$tax] = $terms;
            }
        }

        $items[] = $item;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'   => true,
        'post_type' => $postType,
        'count'     => count($items),
        'posts'     => $items,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Acción update_post_excerpt: escribir post_excerpt de un post existente ──
// Recibe: { "post_id": 123, "excerpt": "Texto generado por IA" }
// Responde: { "success": true, "post_id": 123 }
if ($action === 'update_post_excerpt') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input) || empty($input['post_id']) || !isset($input['excerpt'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Missing required fields: post_id, excerpt']));
    }

    $postId  = (int) $input['post_id'];
    $excerpt = trim((string) $input['excerpt']);

    if (!user_can($fullUser, 'edit_posts')) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Forbidden', 'reason' => 'requires edit_posts capability']));
    }

    $post = get_post($postId);
    if (!$post) {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(['error' => "Post ID {$postId} not found"]));
    }

    $result = wp_update_post([
        'ID'           => $postId,
        'post_excerpt' => wp_strip_all_tags($excerpt),
    ], true);

    if (is_wp_error($result)) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['error' => $result->get_error_message()]));
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'post_id' => $postId,
        'excerpt' => $excerpt,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Acción list_comics: listar cómics con sus IDs de imagen (image_comic) ──
// Usada por el backfill de alt text para saber qué attachments pertenecen a
// cada cómic y qué alt text tienen actualmente.
// Recibe (JSON opcional): { "per_page": 100, "offset": 0 }
// Responde: { "success": true, "count": N, "comics": [ { "id", "title", "attachments": [ { "id", "alt" } ] } ] }
if ($action === 'list_comics') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = [];
    }

    $perPage = max(1, min(100, (int) ($input['per_page'] ?? 100)));
    $offset  = max(0, (int) ($input['offset'] ?? 0));

    if (!user_can($fullUser, 'edit_posts')) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Forbidden', 'reason' => 'requires edit_posts capability']));
    }

    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $perPage,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);

    $items = [];
    foreach ($posts as $p) {
        $imageComic = get_post_meta($p->ID, 'image_comic', true);
        $attachmentIds = [];
        if (is_string($imageComic) && $imageComic !== '') {
            $parts = explode(',', $imageComic);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '' && ctype_digit($part)) {
                    $attachmentIds[] = (int) $part;
                }
            }
        }

        $attachments = [];
        foreach ($attachmentIds as $aid) {
            $att = get_post($aid);
            if (!$att || $att->post_type !== 'attachment') {
                continue;
            }
            $attachments[] = [
                'id'  => $aid,
                'alt' => (string) get_post_meta($aid, '_wp_attachment_image_alt', true),
            ];
        }

        $items[] = [
            'id'          => (int) $p->ID,
            'title'       => $p->post_title,
            'attachments' => $attachments,
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'count'   => count($items),
        'comics'  => $items,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Acción set_attachment_alt: escribir _wp_attachment_image_alt ──
// Recibe: { "attachment_id": 123, "alt_text": "Descripción de la imagen" }
// Responde: { "success": true, "attachment_id": 123 }
if ($action === 'set_attachment_alt') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input) || empty($input['attachment_id']) || !isset($input['alt_text'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Missing required fields: attachment_id, alt_text']));
    }

    $attachmentId = (int) $input['attachment_id'];
    $altText      = sanitize_text_field(trim((string) $input['alt_text']));

    if (!user_can($fullUser, 'upload_files')) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Forbidden', 'reason' => 'requires upload_files capability']));
    }

    $att = get_post($attachmentId);
    if (!$att || $att->post_type !== 'attachment') {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(['error' => "Attachment ID {$attachmentId} not found"]));
    }

    update_post_meta($attachmentId, '_wp_attachment_image_alt', $altText);

    header('Content-Type: application/json');
    echo json_encode([
        'success'       => true,
        'attachment_id' => $attachmentId,
        'alt_text'      => $altText,
    ], JSON_UNESCAPED_UNICODE);
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

// El 3er parámetro de media_handle_sideload es la DESCRIPTION (post_content),
// NO el alt text. El alt real vive en el meta _wp_attachment_image_alt.
if (!is_wp_error($attachmentId) && $altText !== '') {
    update_post_meta($attachmentId, '_wp_attachment_image_alt', sanitize_text_field($altText));
}

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
