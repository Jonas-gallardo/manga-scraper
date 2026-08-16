<?php
/**
 * Plugin Name: Gluglux SEO Meta (Home y Archivos)
 * Description: Fija Meta Title, Meta Description y Open Graph/Twitter de la home y de los archivos de taxonomía (universo, personaje, etiqueta, autor, tipo, idioma). Compatible con SEOPress y Yoast SEO: usa SUS filtros para NO generar duplicados.
 * Version: 1.3
 *
 * INSTALACIÓN:
 *   1. Subir/sobrescribir este archivo en /wp-content/mu-plugins/wp-seo-meta.php
 *   2. Listo: se carga automáticamente, sin activación.
 *
 * IMPORTANTE (evitar duplicados):
 *   Los filtros de SEOPress y Yoast se registran SIEMPRE (solo se ejecutan si el
 *   plugin está activo), por lo que NO se imprime una segunda meta description:
 *   solo se reemplaza el valor del plugin SEO. La meta description propia solo se
 *   imprime si NO hay ningún plugin SEO activo (detección en tiempo de ejecución,
 *   no al cargar el archivo, porque los mu-plugins se cargan antes que los plugins).
 *
 * Nombres de filtros verificados contra SEOPress 10.1:
 *   - seopress_titles_title  (apply_filters con ($value, $context))
 *   - seopress_titles_desc   (apply_filters con ($value, $context))
 *   - seopress_social_og_title / seopress_social_og_desc
 *   - seopress_social_twitter_card_title / seopress_social_twitter_card_desc
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ¿Hay un plugin SEO activo en este momento?
 * Devuelve 'seopress', 'yoast' o '' (ninguno).
 */
function gluglux_seo_detect_plugin(): string
{
    if (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service')) {
        return 'seopress';
    }
    if (defined('WPSEO_VERSION')) {
        return 'yoast';
    }
    return '';
}

/**
 * Título de la home.
 */
function gluglux_seo_get_home_title(): string
{
    return 'Gluglux – Cómics y Mangas en Español';
}

/**
 * Meta description de la home.
 */
function gluglux_seo_get_home_description(): string
{
    return 'Explora un extenso catálogo de cómics, mangas y novelas gráficas en español e inglés, organizados por universo, personaje y etiqueta.';
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
 * Meta description de una taxonomía.
 */
function gluglux_seo_get_tax_description(): string
{
    $term = get_queried_object();
    if ($term && !is_wp_error($term) && isset($term->name)) {
        $nombre = trim((string) $term->name);
        return sprintf(
            'Catálogo de cómics y mangas de %s en Gluglux. Encuentra todos los títulos relacionados con %s, organizados por personaje y etiqueta.',
            $nombre,
            $nombre
        );
    }
    return '';
}

/**
 * Resuelve el título para el contexto actual ('' si no aplica).
 */
function gluglux_seo_compute_title(): string
{
    if (is_front_page() || is_home()) {
        return gluglux_seo_get_home_title();
    }

    if (is_tax() || is_category() || is_tag()) {
        $title = gluglux_seo_get_tax_title();
        if ($title !== '') {
            return $title;
        }
    }

    return '';
}

/**
 * Resuelve la meta description para el contexto actual ('' si no aplica).
 */
function gluglux_seo_compute_description(): string
{
    if (is_front_page() || is_home()) {
        return gluglux_seo_get_home_description();
    }

    if (is_tax() || is_category() || is_tag()) {
        $desc = gluglux_seo_get_tax_description();
        if ($desc !== '') {
            return $desc;
        }
    }

    return '';
}

// ─────────────────────────────────────────────────────────────
// 1. TÍTULO DEL DOCUMENTO
//    pre_get_document_title funciona SIEMPRE (nativo, SEOPress y
//    Yoast pasan por él). Prioridad 20 para ganar al plugin SEO.
// ─────────────────────────────────────────────────────────────
add_filter('pre_get_document_title', function ($title) {
    $t = gluglux_seo_compute_title();
    return ($t !== '') ? $t : $title;
}, 20);

// ─────────────────────────────────────────────────────────────
// 2. SEOPress: reemplazar SUS valores (solo se ejecutan si SEOPress activo)
// ─────────────────────────────────────────────────────────────
add_filter('seopress_titles_title', function ($title, $context = null) {
    $t = gluglux_seo_compute_title();
    return ($t !== '') ? $t : $title;
}, 20, 2);

add_filter('seopress_titles_desc', function ($desc, $context = null) {
    $d = gluglux_seo_compute_description();
    return ($d !== '') ? $d : $desc;
}, 20, 2);

// Open Graph
add_filter('seopress_social_og_title', function ($title) {
    $t = gluglux_seo_compute_title();
    return ($t !== '') ? $t : $title;
}, 20);

add_filter('seopress_social_og_desc', function ($desc) {
    $d = gluglux_seo_compute_description();
    return ($d !== '') ? $d : $desc;
}, 20);

// Twitter Cards
add_filter('seopress_social_twitter_card_title', function ($title) {
    $t = gluglux_seo_compute_title();
    return ($t !== '') ? $t : $title;
}, 20);

add_filter('seopress_social_twitter_card_desc', function ($desc) {
    $d = gluglux_seo_compute_description();
    return ($d !== '') ? $d : $desc;
}, 20);

// ─────────────────────────────────────────────────────────────
// 3. Yoast SEO: reemplazar SUS valores (solo si Yoast activo)
// ─────────────────────────────────────────────────────────────
add_filter('wpseo_title', function ($title) {
    $t = gluglux_seo_compute_title();
    return ($t !== '') ? $t : $title;
}, 20);

add_filter('wpseo_metadesc', function ($desc) {
    $d = gluglux_seo_compute_description();
    return ($d !== '') ? $d : $desc;
}, 20);

add_filter('wpseo_opengraph_title', function ($title) {
    $t = gluglux_seo_compute_title();
    return ($t !== '') ? $t : $title;
}, 20);

add_filter('wpseo_opengraph_desc', function ($desc) {
    $d = gluglux_seo_compute_description();
    return ($d !== '') ? $d : $desc;
}, 20);

add_filter('wpseo_twitter_title', function ($title) {
    $t = gluglux_seo_compute_title();
    return ($t !== '') ? $t : $title;
}, 20);

add_filter('wpseo_twitter_description', function ($desc) {
    $d = gluglux_seo_compute_description();
    return ($d !== '') ? $d : $desc;
}, 20);

// ─────────────────────────────────────────────────────────────
// 4. Fallback nativo: imprime meta description SOLO si no hay
//    plugin SEO activo (detección en tiempo de ejecución).
// ─────────────────────────────────────────────────────────────
add_action('wp_head', function () {
    if (gluglux_seo_detect_plugin() !== '') {
        return; // SEOPress/Yoast ya imprimen la meta description (sobrescrita arriba)
    }
    $d = gluglux_seo_compute_description();
    if ($d !== '') {
        echo '<meta name="description" content="' . esc_attr($d) . '">' . "\n";
    }
}, 1);
