<?php
/**
 * src/Infrastructure/DatabaseMigration.php
 *
 * Dedicated migration class for database schema creation and verification.
 * Should be called explicitly (e.g., during setup or via CLI), NOT on every request.
 *
 * @package ScrapApp\Infrastructure
 */

namespace ScrapApp\Infrastructure;

class DatabaseMigration
{
    private \PDO $pdo;

    /** @var array<string, string> Table name → CREATE TABLE SQL */
    private array $tables;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->tables = $this->defineTables();
    }

    /**
     * Define all table schemas in one place.
     *
     * @return array<string, string>
     */
    private function defineTables(): array
    {
        return [
            'comics_descargados' => "
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
            ",
            'batch_progreso' => "
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
            ",
            'batch_historial' => "
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
            ",
            'mangas_eliminados' => "
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
            ",
            'log_descargas' => "
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
            ",
        ];
    }

    /**
     * Run all pending migrations (CREATE TABLE IF NOT EXISTS).
     *
     * @return array{success: bool, tables: array<string, bool>, errors: array<string, string>}
     */
    public function run(): array
    {
        $results = [
            'success' => true,
            'tables'  => [],
            'errors'  => [],
        ];

        foreach ($this->tables as $name => $sql) {
            try {
                $this->pdo->exec($sql);
                $results['tables'][$name] = true;
            } catch (\PDOException $e) {
                $results['success']          = false;
                $results['tables'][$name]    = false;
                $results['errors'][$name]    = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Check if all required tables exist in the database.
     *
     * @return bool
     */
    public function isMigrated(): bool
    {
        $required = array_keys($this->tables);
        $placeholders = implode(',', array_fill(0, count($required), '?'));

        try {
            $stmt = $this->pdo->prepare("
                SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME IN ({$placeholders})
            ");
            $stmt->execute($required);
            $existing = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            return count($existing) === count($required);
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Get the list of missing tables.
     *
     * @return string[]
     */
    public function getMissingTables(): array
    {
        $required = array_keys($this->tables);
        $placeholders = implode(',', array_fill(0, count($required), '?'));

        try {
            $stmt = $this->pdo->prepare("
                SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME IN ({$placeholders})
            ");
            $stmt->execute($required);
            $existing = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            return array_values(array_diff($required, $existing));
        } catch (\PDOException $e) {
            return $required; // If we can't check, assume all are missing
        }
    }

    /**
     * Get the SQL statement for a specific table.
     *
     * @param string $tableName
     * @return string|null
     */
    public function getTableSql(string $tableName): ?string
    {
        return $this->tables[$tableName] ?? null;
    }

    /**
     * Get all table names managed by this migration.
     *
     * @return string[]
     */
    public function getTableNames(): array
    {
        return array_keys($this->tables);
    }
}
