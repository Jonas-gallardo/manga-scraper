<?php
/**
 * Plugin Name: Gluglux SEO Meta (Taxonomías e índices)
 * Description: Fija Meta Title y Meta Description de los archivos de taxonomía (universo, personaje, etiqueta, autor, tipo, idioma) y de las páginas índice (universos, personajes, etiquetas, idiomas). Genérico: no depende de SEOPress ni de Yoast. Solo rellena la description cuando está vacía, para evitar descripciones duplicadas con SiteSEO Pro u otro plugin SEO.
 * Version: 1.6
 *
 * INSTALACIÓN:
 *   1. Subir/sobrescribir este archivo en /wp-content/mu-plugins/wp-seo-meta.php
 *   2. Listo: se carga automáticamente, sin activación.
 *
 * CÓMO EVITA DESCRIPCIONES DUPLICADAS:
 *   - Título: se fija con el filtro nativo pre_get_document_title (no imprime nada extra).
 *   - Description: se procesa el HTML final con un buffer de salida. Si ya existe un
 *     <meta name="description"> con contenido NO vacío (lo puso SiteSEO Pro u otro),
 *     se respeta tal cual. Solo se rellena si está vacío o no existe. Así nunca hay
 *     dos meta descriptions.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mapa de páginas índice de taxonomías: page_id => [singular, plural].
 * Los IDs se verificaron contra el <body class="page-id-XXX"> de gluglux.com.
 */
function gluglux_seo_get_index_pages(): array
{
    // page_id => [singular, plural, género] (género: 'm' masculino, 'f' femenino)
    return [
        507 => ['universo', 'universos', 'm'],
        552 => ['etiqueta', 'etiquetas', 'f'],
        572 => ['personaje', 'personajes', 'm'],
        577 => ['idioma', 'idiomas', 'm'],
    ];
}

/**
 * ¿La página actual es una de las páginas índice de taxonomías?
 * Devuelve [singular, plural] o null.
 */
function gluglux_seo_get_index_page(): ?array
{
    if (!is_page()) {
        return null;
    }

    $post = get_queried_object();
    if (!$post instanceof WP_Post) {
        return null;
    }

    $pages = gluglux_seo_get_index_pages();
    $id    = (int) $post->ID;

    return $pages[$id] ?? null;
}

/**
 * Título de una taxonomía (universo, personaje, etiqueta, autor, tipo, idioma).
 */
function gluglux_seo_get_tax_title(): string
{
    $term = get_queried_object();
    if ($term && !is_wp_error($term) && isset($term->name)) {
        return sprintf('%s - Cómics | Gluglux', trim((string) $term->name));
    }
    return '';
}

/**
 * Meta description de una taxonomía, con el número de títulos del término.
 */
function gluglux_seo_get_tax_description(): string
{
    $term = get_queried_object();
    if ($term && !is_wp_error($term) && isset($term->name)) {
        $nombre = trim((string) $term->name);
        $count  = isset($term->count) ? (int) $term->count : 0;

        if ($count === 1) {
            $textoCount = '1 título disponible.';
        } elseif ($count > 1) {
            $textoCount = sprintf('%d títulos disponibles.', $count);
        } else {
            $textoCount = 'Explora el catálogo y encuentra tus próximas lecturas.';
        }

        return sprintf(
            'Catálogo de cómics y mangas de %s en Gluglux. %s',
            $nombre,
            $textoCount
        );
    }
    return '';
}

/**
 * Título de una página índice (universos, personajes, etiquetas, idiomas).
 */
function gluglux_seo_get_index_title(array $labels): string
{
    return sprintf('%s de cómics | Gluglux', ucfirst($labels[1]));
}

/**
 * Meta description de una página índice.
 */
function gluglux_seo_get_index_description(array $labels): string
{
    $singular = $labels[0];
    $plural   = $labels[1];
    $genero   = isset($labels[2]) ? $labels[2] : 'm';
    $todos    = ($genero === 'f') ? 'todas' : 'todos';

    return sprintf(
        'Explora %s las %s de cómics y mangas en Gluglux. Navega el catálogo por %s y encuentra tus próximas lecturas.',
        $todos,
        $plural,
        $singular
    );
}

/**
 * Resuelve el título para el contexto actual ('' si no aplica).
 */
function gluglux_seo_compute_title(): string
{
    if (is_tax() || is_category() || is_tag()) {
        $title = gluglux_seo_get_tax_title();
        if ($title !== '') {
            return $title;
        }
    }

    $index = gluglux_seo_get_index_page();
    if ($index !== null) {
        return gluglux_seo_get_index_title($index);
    }

    return '';
}

/**
 * Resuelve la meta description para el contexto actual ('' si no aplica).
 */
function gluglux_seo_compute_description(): string
{
    if (is_tax() || is_category() || is_tag()) {
        $desc = gluglux_seo_get_tax_description();
        if ($desc !== '') {
            return $desc;
        }
    }

    $index = gluglux_seo_get_index_page();
    if ($index !== null) {
        return gluglux_seo_get_index_description($index);
    }

    return '';
}

/**
 * ¿La petición actual es una taxonomía o una página índice que nos interesa?
 */
function gluglux_seo_is_target_context(): bool
{
    return (bool) (is_tax() || is_category() || is_tag() || gluglux_seo_get_index_page() !== null);
}

// ─────────────────────────────────────────────────────────────
// 1. TÍTULO DEL DOCUMENTO
//    pre_get_document_title es el filtro nativo por el que pasan
//    prácticamente todos los plugins SEO. Prioridad alta para
//    imponerse sobre SiteSEO Pro en las taxonomías e índices.
// ─────────────────────────────────────────────────────────────
add_filter('pre_get_document_title', function ($title) {
    if (!gluglux_seo_is_target_context()) {
        return $title;
    }
    $t = gluglux_seo_compute_title();
    return ($t !== '') ? $t : $title;
}, 999);

// ─────────────────────────────────────────────────────────────
// 2. META DESCRIPTION (sin duplicados)
//    Buffer de salida sobre el HTML final: solo rellena la
//    description si está vacía o no existe. Respeta cualquier
//    description con contenido que ya haya impreso el plugin SEO.
// ─────────────────────────────────────────────────────────────
function gluglux_seo_meta_start_buffer(): void
{
    if (!gluglux_seo_is_target_context()) {
        return;
    }
    ob_start('gluglux_seo_meta_process_html');
}

function gluglux_seo_meta_process_html(string $html): string
{
    $desc = gluglux_seo_compute_description();
    if ($desc === '') {
        return $html;
    }

    $safe    = esc_attr($desc);
    $pattern = '#<meta[^>]*name=["\']description["\'][^>]*>#i';

    // Ya existe un <meta name="description">: rellenar solo si está vacío.
    if (preg_match($pattern, $html)) {
        return preg_replace_callback($pattern, function ($m) use ($safe) {
            $tag = $m[0];

            // Tiene content con texto? → lo respetamos (evita duplicar/sobrescribir).
            if (preg_match('#content=["\']([^"\']*)["\']#i', $tag, $cm)) {
                if (trim((string) $cm[1]) !== '') {
                    return $tag;
                }
                return str_replace($cm[0], 'content="' . $safe . '"', $tag);
            }

            // Meta sin atributo content: se lo añadimos.
            return str_replace('<meta', '<meta content="' . $safe . '"', $tag);
        }, $html);
    }

    // No existe: insertarla justo después de <title>, o al inicio de <head>.
    $meta = '<meta name="description" content="' . $safe . '">' . "\n";
    $html = preg_replace('#(<title[^>]*>.*?</title>)#is', '$1' . "\n" . $meta, $html, 1, $count);
    if ($count === 0) {
        $html = preg_replace('#<head[^>]*>#i', '$0' . "\n" . $meta, $html, 1);
    }

    return $html;
}

add_action('template_redirect', 'gluglux_seo_meta_start_buffer', 1);
