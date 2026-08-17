# Instalación de las correcciones SEO — Gluglux

Este documento explica **qué subir al servidor**, **en qué orden** y **cómo ejecutar
el backfill** de los posts ya publicados. Resumen de las 3 piezas:

| # | Archivo | Dónde se sube | Qué hace |
|---|---------|---------------|----------|
| 1 | [`gluglux-seo-fixes.php`](gluglux-seo-fixes.php) | `/wp-content/mu-plugins/` | Corrige el renderizado: inyecta `alt`, `loading="lazy"`, `decoding="async"` y dimensiones en las imágenes de la galería ACF. Además inyecta el `post_content` (Sinopsis + Ficha técnica) dentro de un acordeón `<details>` colapsado, delante de la galería. |
| 2 | [`wp-media-bridge.php`](wp-media-bridge.php) | Raíz de WordPress (junto a `wp-config.php`) | Añade la acción `update_post_content` y devuelve `content` + `page_count` en `list_posts`. |
| 3 | [`content_backfill.php`](content_backfill.php) | **NO se sube al servidor.** Se ejecuta desde la app local. | Rellena el `post_content` de los posts existentes vía el bridge. |

---

## Paso 1 — Subir el mu-plugin de renderizado (alt/lazy + post_content)

1. Subir [`gluglux-seo-fixes.php`](gluglux-seo-fixes.php) a:

   ```
   /wp-content/mu-plugins/gluglux-seo-fixes.php
   ```

2. No requiere activación: los mu-plugins se cargan solos.

3. **Verificación**: abrir una ficha de cómic y revisar el HTML:
   - Las imágenes `<img class="comic-page-img">` ahora llevan `alt="..."`
   - La primera imagen NO tiene `loading="lazy"` (es la LCP)
   - El resto tienen `loading="lazy"` + `decoding="async"`
   - Tienen `width`/`height` para evitar CLS
   - Aparece el bloque `<!-- gluglux-seo-content:start -->` con un acordeón
     `<details class="gluglux-seo-content">` que contiene la Sinopsis, la Ficha
     técnica y el recuento de páginas, justo antes de
     `<div class="comic-reader-container">`. El usuario solo ve la línea
     "Sinopsis y ficha técnica"; el contenido se abre al hacer clic.

   > Nota: no modifica la base de datos. El alt ya estaba guardado en
   > `_wp_attachment_image_alt`; este plugin solo hace que se IMPRIMA en el HTML.

   > Importante (por qué se inyecta el contenido y cómo se muestra): la plantilla
   > Elementor de las fichas de cómic NO llama a `the_content()`; solo pinta el
   > shortcode de la galería ACF. Por eso el `post_content` que escribe el backfill
   > queda guardado en la base de datos pero invisible en el HTML público. La
   > función `gluglux_seo_fixes_add_content()` de este mu-plugin lo inserta delante
   > de la galería dentro de un acordeón `<details>` colapsado: el texto está en el
   > DOM y es indexable, pero no rompe el diseño de la ficha. NO se oculta con
   > `display:none` / `visibility:hidden` / fuera de pantalla, porque Google trata
   > el texto oculto como cloaking y lo devalúa. Si ya subiste una versión anterior
   > de este archivo, debes SOBRESCRIBIRLA con esta versión actualizada.

---

## Paso 2 — Actualizar el bridge (solo si harás backfill de contenido)

El bridge actual en el servidor no tiene la acción `update_post_content`.
Hay que **sobrescribir** [`wp-media-bridge.php`](wp-media-bridge.php) en la raíz
de WordPress (mismo sitio donde ya está). Se conservan todas las acciones previas
(`create_post`, `list_posts`, `update_post_excerpt`, `list_comics`,
`set_attachment_alt`, `ensure_term`, `set_post_terms`, subida de imágenes).

Verificación rápida tras subirlo:

```
https://gluglux.com/wp-media-bridge.php?action=list_posts&test=1
```

No importa si responde 401 (es esperado sin credenciales): la acción nueva existe.

---

## Paso 3 — Ejecutar el backfill de contenido (posts existentes)

Desde la app local (`/opt/lampp/htdocs/scrap`), con `config.json` apuntando a
gluglux.com y credenciales de Application Password:

```bash
# Prueba en seco (no escribe nada): 5 posts
php content_backfill.php 0 5 --dry-run

# Lote real: 50 posts desde el offset 0, solo los que tienen post_content vacío
php content_backfill.php 0 50 --only-empty

# Todo el catálogo (1.107 posts), en lotes de 100, solo vacíos
php content_backfill.php --all --only-empty

# Re-generar TODO (sobrescribe), útil si cambias la plantilla
php content_backfill.php --all --force
```

Qué hace el backfill:
- Toma el `post_excerpt` (ya generado por DeepSeek en la Fase 1) como **Sinopsis**.
- Construye una **Ficha técnica** con universo, personajes, autor, tipo, idioma y etiquetas.
- Añade el **recuento de páginas** (del campo ACF `image_comic`).
- Añade un encabezado **Galería** como marcador semántico.

> El backfill NO toca las imágenes: las imágenes siguen viviendo en el campo ACF
> `image_comic`. Solo escribe texto en `post_content`.

---

## Paso 4 — Correcciones para posts NUEVOS (lado app, no servidor)

Estos cambios ya están aplicados en [`src/WP/WPPublisher.php`](src/WP/WPPublisher.php)
y actúan cuando el scraper publique un cómic nuevo:

- **Título limpio**: elimina códigos fuente y paréntesis (`[wjs07] Título (Serie)` → `Título`).
- **Slug limpio**: URL sin sufijos `-2` (usa [`sanitizeTitleSlug()`](src/WP/WPPublisher.php:1284)).
- **`post_content` semántico**: cada post nuevo nace con Sinopsis + Ficha técnica + páginas + Galería (misma plantilla del backfill).

No requieren subir nada al servidor. Solo asegurarse de que el bridge del servidor
esté actualizado (Paso 2) para que `create_post` siga funcionando igual.

---

## Resumen de tareas manuales en WordPress (pendientes tuyas)

Estas son las cosas que quedan de tu lado (no las resuelve el código):

1. **Meta description de taxonomías**: ya existe el mu-plugin
   [`wp-seo-meta.php`](wp-seo-meta.php). Verificar que esté subido en
   `/wp-content/mu-plugins/` y, si no, subirlo/sobrescribirlo.
2. **Títulos de términos en minúsculas sin branding**: editar los términos
   (universo, personaje, etiqueta…) y poner nombre con mayúsculas.
3. **Rendimiento / TTFB**: evaluar caché de página (LiteSpeed Cache) o CDN.
4. **Twitter Cards `summary_large_image`**: ajustar en SEOPress si quieres tarjetas grandes.

---

## Orden recomendado de ejecución

1. Subir [`gluglux-seo-fixes.php`](gluglux-seo-fixes.php) → alt/lazy quedan resueltos de inmediato.
2. Subir [`wp-media-bridge.php`](wp-media-bridge.php) actualizado.
3. Correr `php content_backfill.php 0 5 --dry-run` (prueba).
4. Correr `php content_backfill.php --all --only-empty` (producción).
5. **Volver a subir la versión actualizada** de [`gluglux-seo-fixes.php`](gluglux-seo-fixes.php)
   (la que inyecta el `post_content`) si subiste una versión anterior antes del backfill.
6. Purgar caché (LiteSpeed Cache + Cloudflare) y verificar que el bloque
   `<!-- gluglux-seo-content:start -->` aparezca en una ficha.
7. Dejar para después tus tareas manuales de WordPress.
