<?php

declare(strict_types=1);

namespace ScrapApp\Infrastructure;

/**
 * JsonLoader.php
 *
 * Cargador de archivos JSON con caché en memoria.
 * Centraliza la lectura de archivos de datos (tags, universes, mappings)
 * y evita leer el disco múltiples veces en una misma petición.
 *
 * @package ScrapApp
 * @subpackage Infrastructure
 */
class JsonLoader
{
    /** @var array<string, mixed> Cache en memoria: filename => data */
    private static array $cache = [];

    /** @var string Ruta base donde están los archivos JSON */
    private string $basePath;

    /**
     * @param string|null $basePath Ruta al directorio de datos (default: data/ junto al proyecto)
     */
    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 2) . '/data';
    }

    /**
     * Carga un archivo JSON y lo decodifica como array.
     *
     * @param string $filename Nombre del archivo (ej: "tags.json")
     * @param bool $forceRefresh Si true, ignora la caché
     * @return array Los datos decodificados, o [] si no existe
     */
    public function loadArray(string $filename, bool $forceRefresh = false): array
    {
        $cacheKey = $this->basePath . '/' . $filename;

        if (!$forceRefresh && isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $filePath = $this->basePath . '/' . $filename;
        if (!file_exists($filePath)) {
            self::$cache[$cacheKey] = [];
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            self::$cache[$cacheKey] = [];
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            self::$cache[$cacheKey] = [];
            return [];
        }

        self::$cache[$cacheKey] = $data;
        return $data;
    }

    /**
     Guarda un array en un archivo JSON.
     *
     * @param string $filename Nombre del archivo
     * @param array $data Datos a guardar
     * @param int $flags Opciones de json_encode
     * @return bool True si se guardó correctamente
     */
    public function saveArray(string $filename, array $data, int $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT): bool
    {
        $filePath = $this->basePath . '/' . $filename;
        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $json = json_encode($data, $flags);
        if ($json === false) {
            return false;
        }

        $result = file_put_contents($filePath, $json, LOCK_EX);
        if ($result !== false) {
            // Invalidar caché
            $cacheKey = $this->basePath . '/' . $filename;
            unset(self::$cache[$cacheKey]);
            return true;
        }

        return false;
    }

    /**
     * Limpia toda la caché en memoria.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
