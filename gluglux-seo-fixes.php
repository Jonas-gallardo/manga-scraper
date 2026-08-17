<?php
/**
 * Plugin Name: Gluglux SEO Fixes
 * Description: Corrige el HTML de las fichas de cómic en gluglux.com: inyecta el alt text (que ya está en la biblioteca de medios) en las imágenes de la galería, añade lazy-load y dimensiones, e inyecta el post_content (Sinopsis + Ficha técnica) delante de la galería. Resuelve alt y dimensiones con una sola precarga por post para no disparar el TTFB. No modifica la base de datos.
 * Version: 1.2
 *
 * INSTALACIÓN:
 *   1. Subir este archivo a /wp-content/mu-plugins/gluglux-seo-fixes.php
 *   2. Listo: se carga automáticamente, sin activación.
 *
 * QUÉ HACE:
 *   El backfill de alt text guardó correctamente el alt en _wp_attachment_image_alt,
 *   pero el widget de la galería (plugin ACF Photo Gallery) renderiza las páginas
 *   como <img class="comic-page-img"> SIN el atributo alt. Además, la plantilla
 *   Elementor de las fichas NO llama a the_content(), así que el post_content
 *   (Sinopsis + Ficha técnica) queda guardado pero invisible. Este mu-plugin captura
 *   la salida HTML final de cada ficha de cómic y reinserta:
 *     - el post_content formateado DENTRO de un <details> colapsado (acordeón),
 *       justo antes de <div class="comic-reader-container">. Así el texto está en
 *       el DOM (indexable) pero el usuario solo ve la línea "Sinopsis y ficha
 *       técnica" hasta que decide abrirla. NO se oculta con display:none, que
 *       Google interpreta como texto oculto/cloaking.
 *     - alt descriptivo (desde la biblioteca) o un fallback "Página N de <título>"
 *     - loading="lazy" + decoding="async" (excepto la primera imagen = LCP)
 *     - width/height (desde los metadatos del attachment, para evitar CLS)
 *
 * RENDIMIENTO (importante): en lugar de resolver alt y dimensiones imagen por
 * imagen con attachment_url_to_postid() (que hace una consulta LIKE por cada
 * imagen y elevaba el TTFB varios segundos en cómics de 70+ páginas), el plugin
 * precarga en una sola pasada TODOS los attachments del campo ACF image_comic
 * (get_posts + update_meta_cache) y resuelve por URL o por nombre de archivo.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Punto de entrada: activar el buffer de salida solo en fichas de cómic.
 */
function gluglux_seo_fixes_start(): void
{
    if (is_singular('post')) {
        ob_start('gluglux_seo_fixes_enhance_html');
    }
}
add_action('template_redirect', 'gluglux_seo_fixes_start', 1);

/**
 * Callback del output buffer: post-procesa todo el HTML de la ficha.
 *
 * @param string $html HTML completo de la página.
 * @return string HTML mejorado.
 */
function gluglux_seo_fixes_enhance_html(string $html): string
{
    if (stripos($html, 'comic-page-img') === false) {
        return $html;
    }

    $html = gluglux_seo_fixes_add_content($html);
    $html = gluglux_seo_fixes_add_alt($html);
    $html = gluglux_seo_fixes_add_lazy_and_dimensions($html);

    return $html;
}

/**
 * Inyecta el post_content (Sinopsis + Ficha técnica + páginas) ANTES de la
 * galería si la plantilla no renderiza the_content().
 *
 * Los cómics de gluglux usan una plantilla Elementor que solo pinta la galería
 * (shortcode ACF) y no llama a the_content(). Por eso el cuerpo queda guardado
 * en la base de datos pero invisible en el HTML público. Aquí se inserta el
 * contenido aplicando wpautop y los shortcodes (para respetar el formato) justo
 * antes del contenedor <div class="comic-reader-container">.
 */
function gluglux_seo_fixes_add_content(string $html): string
{
    // Solo en singular (ficha de cómic), con un post real y con contenido.
    if (!is_singular('post')) {
        return $html;
    }

    $post = get_queried_object();
    if (!$post instanceof WP_Post || trim((string) $post->post_content) === '') {
        return $html;
    }

    // Si la plantilla ya renderizó el contenido, no duplicar.
    if (stripos($html, 'gluglux-seo-content') !== false) {
        return $html;
    }

    // Formatear igual que lo haría the_content(): shortcodes + párrafos.
    $content = apply_filters('the_content', $post->post_content);

    // Acordeón nativo <details> colapsado por defecto: el contenido queda en el
    // DOM y es indexable, pero el usuario solo ve un resumen de una línea hasta
    // que lo abre. NO usar display:none / visibility:hidden / off-screen porque
    // Google lo trata como texto oculto y lo devalúa o penaliza (cloaking).
    // Estilos con alcance: reducen los H2 (Sinopsis/Ficha técnica/Galería) y el
    // texto del cuerpo para que el bloque no rompa la estética de la ficha.
    $styles = '<style>'
        . '.gluglux-seo-content h2{margin:0 0 6px;font-size:15px;font-weight:700;color:#222;}'
        . '.gluglux-seo-content h2:not(:first-child){margin-top:12px;}'
        . '.gluglux-seo-content p,.gluglux-seo-content li{font-size:13px;line-height:1.5;color:#444;}'
        . '.gluglux-seo-content ul{margin:0 0 8px;padding-left:18px;}'
        . '</style>';

    $block = "\n<!-- gluglux-seo-content:start -->\n"
        . $styles
        . '<details class="gluglux-seo-content" style="max-width:800px;margin:0 auto 24px;border:1px solid #e2e2e2;border-radius:8px;padding:12px 16px;background:#fafafa;">'
        . '<summary style="cursor:pointer;font-weight:600;font-size:14px;color:#333;">Sinopsis y ficha técnica</summary>'
        . '<div class="gluglux-seo-content-body" style="margin-top:12px;">'
        . $content
        . '</div>'
        . "</details>\n<!-- gluglux-seo-content:end -->\n";

    // Insertar antes del contenedor de la galería.
    $needle = '<div class="comic-reader-container">';
    if (($pos = strpos($html, $needle)) !== false) {
        return substr($html, 0, $pos) . $block . substr($html, $pos);
    }

    // Fallback: antes de la primera imagen de página.
    if (preg_match('/<img\b[^>]*class="[^"]*comic-page-img[^"]*"[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        return substr($html, 0, $pos) . $block . substr($html, $pos);
    }

    return $html;
}

/**
 * Añade el atributo alt a cada <img class="comic-page-img"> que no lo tenga.
 */
function gluglux_seo_fixes_add_alt(string $html): string
{
    $postTitle = get_the_title();

    return preg_replace_callback(
        '/<img\b[^>]*class="[^"]*comic-page-img[^"]*"[^>]*>/i',
        static function (array $matches) use ($postTitle): string {
            $tag = $matches[0];

            // Ya tiene alt → no tocar
            if (preg_match('/\balt\s*=/i', $tag)) {
                return $tag;
            }

            $src = gluglux_seo_fixes_extract_attr($tag, 'src');
            $alt = $src !== '' ? gluglux_seo_fixes_get_alt_by_src($src) : '';

            if ($alt === '') {
                $page = gluglux_seo_fixes_extract_page_number($src);
                if ($page > 0) {
                    $alt = sprintf('Página %d de %s', $page, $postTitle !== '' ? $postTitle : 'este cómic');
                } else {
                    $alt = $postTitle !== '' ? $postTitle : 'Página del cómic';
                }
            }

            return gluglux_seo_fixes_insert_attr($tag, 'alt', esc_attr($alt));
        },
        $html
    );
}

/**
 * Añade loading="lazy", decoding="async" y dimensiones a las imágenes de la galería.
 * La primera imagen NO se marca lazy (es la imagen LCP).
 */
function gluglux_seo_fixes_add_lazy_and_dimensions(string $html): string
{
    $first = true;

    return preg_replace_callback(
        '/<img\b[^>]*class="[^"]*comic-page-img[^"]*"[^>]*>/i',
        static function (array $matches) use (&$first): string {
            $tag = $matches[0];
            $src = gluglux_seo_fixes_extract_attr($tag, 'src');

            // decoding async siempre
            if (!preg_match('/\bdecoding\s*=/i', $tag)) {
                $tag = gluglux_seo_fixes_insert_attr($tag, 'decoding', 'async');
            }

            // lazy load: solo desde la segunda imagen
            if (!$first) {
                if (!preg_match('/\bloading\s*=/i', $tag)) {
                    $tag = gluglux_seo_fixes_insert_attr($tag, 'loading', 'lazy');
                }
            } else {
                $first = false;
            }

            // dimensiones (anti CLS)
            if ($src !== '' && !preg_match('/\bwidth\s*=/i', $tag)) {
                $size = gluglux_seo_fixes_get_dimensions_by_src($src);
                if ($size !== null) {
                    $tag = gluglux_seo_fixes_insert_attr($tag, 'width', (string) $size[0]);
                    $tag = gluglux_seo_fixes_insert_attr($tag, 'height', (string) $size[1]);
                }
            }

            return $tag;
        },
        $html
    );
}

/**
 * Precarga en UNA pasada el alt y las dimensiones de TODAS las imágenes de la
 * galería del post (campo ACF image_comic), evitando attachment_url_to_postid()
 * por imagen. attachment_url_to_postid() hace una consulta LIKE sobre
 * _wp_attached_file por cada imagen; con cómics de 70+ páginas eso dispara el
 * TTFB a varios segundos. Aquí se resuelve todo con 3 consultas en total.
 */
function gluglux_seo_fixes_preload_media(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];

    $post = get_post();
    if (!$post instanceof WP_Post) {
        return $cache;
    }

    $idsString = (string) get_post_meta($post->ID, 'image_comic', true);
    if ($idsString === '') {
        return $cache;
    }

    $ids = array_values(array_filter(array_map('intval', explode(',', $idsString))));
    if (empty($ids)) {
        return $cache;
    }

    // 1) Todos los attachment posts en una sola consulta.
    $attachments = get_posts([
        'post_type'   => 'attachment',
        'post_status' => 'inherit',
        'post__in'    => $ids,
        'numberposts' => -1,
        'orderby'     => 'post__in',
    ]);

    // 2) Toda la metadata (alt, _wp_attached_file, _wp_attachment_metadata) de golpe.
    update_meta_cache('post', $ids);

    foreach ($attachments as $att) {
        $url  = (string) wp_get_attachment_url($att->ID);
        $meta = wp_get_attachment_metadata($att->ID);

        $data = [
            'alt'    => (string) get_post_meta($att->ID, '_wp_attachment_image_alt', true),
            'width'  => is_array($meta) && !empty($meta['width']) ? (int) $meta['width'] : 0,
            'height' => is_array($meta) && !empty($meta['height']) ? (int) $meta['height'] : 0,
        ];

        if ($url !== '') {
            $cache[$url] = $data;
        }

        // Índice por nombre de archivo: robusto ante diferencias de host/scheme.
        $file = basename((string) parse_url($url, PHP_URL_PATH));
        if ($file !== '') {
            $cache['file:' . $file] = $data;
        }
    }

    return $cache;
}

/**
 * Resuelve el alt text de una imagen por su URL usando la precarga en memoria.
 * Fallback a attachment_url_to_postid() solo si la precarga no cubre la URL.
 */
function gluglux_seo_fixes_get_alt_by_src(string $src): string
{
    static $cache = [];

    if (array_key_exists($src, $cache)) {
        return $cache[$src];
    }

    $alt   = '';
    $media = gluglux_seo_fixes_preload_media();
    $file  = basename((string) parse_url($src, PHP_URL_PATH));

    if (isset($media['file:' . $file]['alt'])) {
        $alt = $media['file:' . $file]['alt'];
    } elseif (isset($media[$src]['alt'])) {
        $alt = $media[$src]['alt'];
    } elseif (function_exists('attachment_url_to_postid')) {
        $attachmentId = attachment_url_to_postid($src);
        if ($attachmentId > 0) {
            $alt = (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true);
        }
    }

    $alt = trim($alt);
    $cache[$src] = $alt;
    return $alt;
}

/**
 * Obtiene width/height de una imagen por su URL usando la precarga en memoria.
 */
function gluglux_seo_fixes_get_dimensions_by_src(string $src): ?array
{
    static $cache = [];

    if (array_key_exists($src, $cache)) {
        return $cache[$src];
    }

    $dimensions = null;
    $media      = gluglux_seo_fixes_preload_media();
    $file       = basename((string) parse_url($src, PHP_URL_PATH));

    $data = null;
    if (isset($media['file:' . $file])) {
        $data = $media['file:' . $file];
    } elseif (isset($media[$src])) {
        $data = $media[$src];
    }

    if ($data !== null && !empty($data['width']) && !empty($data['height'])) {
        $dimensions = [$data['width'], $data['height']];
    } elseif (function_exists('attachment_url_to_postid')) {
        $attachmentId = attachment_url_to_postid($src);
        if ($attachmentId > 0) {
            $meta = wp_get_attachment_metadata($attachmentId);
            if (is_array($meta) && !empty($meta['width']) && !empty($meta['height'])) {
                $dimensions = [(int) $meta['width'], (int) $meta['height']];
            }
        }
    }

    $cache[$src] = $dimensions;
    return $dimensions;
}

/**
 * Extrae el valor de un atributo HTML (sin comillas o con comillas simples/dobles).
 */
function gluglux_seo_fixes_extract_attr(string $tag, string $attr): string
{
    if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*["\']([^"\']*)["\']/i', $tag, $m)) {
        return $m[1];
    }
    if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*([^\s>]+)/i', $tag, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Inserta un atributo antes del cierre ">" de la etiqueta.
 */
function gluglux_seo_fixes_insert_attr(string $tag, string $attr, string $value): string
{
    $tag = rtrim($tag);
    // Quitar el ">" final (con o sin "/>")
    if (substr($tag, -2) === '/>') {
        return substr($tag, 0, -2) . ' ' . $attr . '="' . $value . '" />';
    }
    if (substr($tag, -1) === '>') {
        return substr($tag, 0, -1) . ' ' . $attr . '="' . $value . '">';
    }
    return $tag . ' ' . $attr . '="' . $value . '"';
}

/**
 * Extrae el número de página del nombre del archivo (patrón: -pagina-N.webp).
 */
function gluglux_seo_fixes_extract_page_number(string $src): int
{
    if (preg_match('/pagina-(\d+)(?:\.webp)?/i', $src, $m)) {
        return (int) $m[1];
    }
    return 0;
}
