-- ============================================================
-- Migration: Agregar columna taxonomias a comics_descargados
--
-- Ejecutar solo si la base de datos ya existe (actualización).
-- Si es instalación nueva, el schema está en database.sql.
-- ============================================================

ALTER TABLE comics_descargados
    ADD COLUMN taxonomias JSON DEFAULT NULL
    COMMENT 'Taxonomías procesadas (JSON): idioma, universos, tipos, autores, etiquetas'
    AFTER tags;
