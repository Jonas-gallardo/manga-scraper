<?php
/**
 * conexion.php
 * 
 * Conexión PDO a la base de datos MySQL.
 * Si no existe config.json (configuración pendiente), redirige a setup.php
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
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // ── Auto-crear tablas si no existen ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS comics_descargados (
            id_fuente       INT          NOT NULL PRIMARY KEY COMMENT 'ID único extraído de la URL del cómic',
            titulo          VARCHAR(255) NOT NULL             COMMENT 'Título del cómic',
            universo        VARCHAR(255) DEFAULT NULL         COMMENT 'Categoría / universo al que pertenece',
            autor           VARCHAR(255) DEFAULT NULL         COMMENT 'Autor del cómic',
            artista         VARCHAR(255) DEFAULT NULL         COMMENT 'Artista / ilustrador',
            tags            TEXT         DEFAULT NULL         COMMENT 'Etiquetas separadas por coma',
            taxonomias      JSON         DEFAULT NULL         COMMENT 'Taxonomías procesadas (JSON): idioma, universos, tipos, autores, etiquetas',
            sinopsis        TEXT         DEFAULT NULL         COMMENT 'Descripción corta del cómic',
            total_paginas   INT          DEFAULT 0            COMMENT 'Número total de páginas descargadas',
            paginas_ok      INT          DEFAULT 0            COMMENT 'Páginas descargadas exitosamente',
            paginas_fail    INT          DEFAULT 0            COMMENT 'Páginas con error',
            tamano_bytes    BIGINT       DEFAULT 0            COMMENT 'Tamaño total en disco (bytes)',
            idioma          VARCHAR(10)  DEFAULT NULL         COMMENT 'Idioma detectado',
            rating          DECIMAL(2,1) DEFAULT NULL         COMMENT 'Calificación (0-10)',
            estado          ENUM('completo','parcial','error','descargando')
                                           DEFAULT 'descargando' COMMENT 'Estado de la descarga',
            ruta_carpeta    VARCHAR(500) DEFAULT NULL         COMMENT 'Ruta absoluta de la carpeta en disco',
            fecha_descarga  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP COMMENT 'Momento en que se completó la descarga',
            fecha_actualiz  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última actualización',
            INDEX idx_universo (universo),
            INDEX idx_estado (estado),
            INDEX idx_fecha (fecha_descarga),
            FULLTEXT idx_titulo_tags (titulo, tags)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS batch_progreso (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            universo        VARCHAR(255) NOT NULL             COMMENT 'Nombre del universo',
            url_base        VARCHAR(500) NOT NULL             COMMENT 'URL base del listado',
            pagina_actual   INT          DEFAULT 1            COMMENT 'Última página del listado procesada',
            pagina_fin      INT          DEFAULT NULL         COMMENT 'Página límite (NULL = sin límite)',
            comics_obtenidos INT         DEFAULT 0            COMMENT 'Total de enlaces encontrados hasta ahora',
            comics_descargados INT       DEFAULT 0            COMMENT 'Cómics descargados en esta sesión',
            comics_omitidos  INT         DEFAULT 0            COMMENT 'Cómics omitidos por duplicado',
            comics_errores   INT         DEFAULT 0            COMMENT 'Cómics con error',
            max_comics      INT          DEFAULT 50           COMMENT 'Máximo de cómics a descargar en total',
            en_progreso     BOOLEAN      DEFAULT FALSE        COMMENT 'Si hay una descarga activa',
            fecha_inicio    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            fecha_fin       DATETIME     DEFAULT NULL         COMMENT 'Fecha de finalización',
            UNIQUE KEY uk_universo (universo),
            INDEX idx_en_progreso (en_progreso)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS batch_historial (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            url_base        VARCHAR(500) NOT NULL             COMMENT 'URL base del listado',
            universo        VARCHAR(255) DEFAULT NULL         COMMENT 'Nombre del universo',
            ultima_pagina   INT          NOT NULL DEFAULT 0   COMMENT 'Última página del listado procesada',
            pagina_inicial  INT          NOT NULL DEFAULT 1   COMMENT 'Página por donde se empezó',
            max_comics      INT          DEFAULT 0            COMMENT 'Máximo de cómics configurado',
            total_enlaces   INT          DEFAULT 0            COMMENT 'Total de enlaces encontrados',
            comics_descargados INT       DEFAULT 0            COMMENT 'Cómics descargados',
            comics_omitidos  INT         DEFAULT 0            COMMENT 'Cómics omitidos (duplicados)',
            comics_errores   INT         DEFAULT 0            COMMENT 'Cómics con error',
            completado      BOOLEAN      DEFAULT TRUE         COMMENT 'Si el batch se completó',
            fecha_ejecucion TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Momento de la ejecución',
            UNIQUE KEY uk_url_base (url_base(191)),
            INDEX idx_universo (universo),
            INDEX idx_fecha (fecha_ejecucion)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mangas_eliminados (
            id_fuente       INT          NOT NULL PRIMARY KEY COMMENT 'ID del manga eliminado',
            titulo          VARCHAR(255) NOT NULL             COMMENT 'Título del manga',
            universo        VARCHAR(255) DEFAULT NULL         COMMENT 'Universo al que pertenecía',
            autor           VARCHAR(255) DEFAULT NULL         COMMENT 'Autor del manga',
            total_paginas   INT          DEFAULT 0            COMMENT 'Páginas que tenía',
            paginas_ok      INT          DEFAULT 0            COMMENT 'Páginas descargadas',
            tamano_bytes    BIGINT       DEFAULT 0            COMMENT 'Tamaño en disco (bytes)',
            motivo          VARCHAR(255) DEFAULT 'Eliminado por usuario' COMMENT 'Motivo de eliminación',
            fecha_eliminacion TIMESTAMP  DEFAULT CURRENT_TIMESTAMP COMMENT 'Cuándo se eliminó',
            fecha_origen    TIMESTAMP    NULL                 COMMENT 'Fecha original de descarga',
            INDEX idx_universo (universo),
            INDEX idx_fecha_eliminacion (fecha_eliminacion)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS log_descargas (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            id_fuente       INT          DEFAULT NULL         COMMENT 'ID del cómic involucrado',
            tipo            ENUM('info','success','warning','error','progress')
                                           DEFAULT 'info'        COMMENT 'Tipo de evento',
            mensaje         TEXT         NOT NULL             COMMENT 'Mensaje descriptivo',
            fecha           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_id_fuente (id_fuente),
            INDEX idx_tipo (tipo),
            INDEX idx_fecha (fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
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
