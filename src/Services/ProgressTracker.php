<?php

declare(strict_types=1);

namespace ScrapApp\Services;

/**
 * ProgressTracker.php
 *
 * Gestiona el envío de progreso en tiempo real (SSE-like via JSON),
 * logging a BD y archivo, verificación de señal de stop, y pausas.
 *
 * Reemplaza las funciones globales send_progress(), log_to_db(),
 * log_to_file(), check_stop_signal() y delay() de scraper.php.
 *
 * @package ScrapApp
 * @subpackage Services
 */
class ProgressTracker
{
    private ?\PDO $pdo;

    /**
     * @param \PDO|null $pdo Conexión PDO opcional (para log_to_db)
     */
    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Envía una línea JSON al cliente y fuerza el flush.
     *
     * @param array<string, mixed> $data Datos a enviar como JSON
     */
    public function sendProgress(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Registra un evento en la tabla log_descargas.
     *
     * @param int|null $idFuente ID del cómic asociado
     * @param string $tipo Tipo de log (success, warning, error, info)
     * @param string $mensaje Mensaje descriptivo
     */
    public function logToDb(?int $idFuente, string $tipo, string $mensaje): void
    {
        if ($this->pdo === null) {
            return;
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO log_descargas (id_fuente, tipo, mensaje) VALUES (?, ?, ?)'
            );
            $stmt->execute([$idFuente, $tipo, $mensaje]);
        } catch (\Exception $e) {
            // Si falla el logging, no interrumpimos el proceso
        }
    }

    /**
     * Escribe en archivo de log rotativo.
     *
     * @param string $message Mensaje a registrar
     */
    public function logToFile(string $message): void
    {
        $dir = defined('LOG_DIR') ? LOG_DIR : __DIR__ . '/../../logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_dir($dir) && !is_writable($dir)) {
            @chmod($dir, 0777);
        }

        $file = defined('LOG_FILE') ? LOG_FILE : $dir . '/scraper.log';
        $maxSize = defined('LOG_MAX_SIZE') ? LOG_MAX_SIZE : 5 * 1024 * 1024;

        // Rotar si excede el tamaño máximo
        if (file_exists($file) && filesize($file) > $maxSize) {
            $backup = $file . '.' . date('Ymd-His');
            @rename($file, $backup);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Verifica si el usuario solicitó la detención del proceso.
     * Comprueba tanto connection_aborted() como el archivo de señal de stop.
     *
     * @return bool True si se debe detener el proceso
     */
    public function checkStopSignal(): bool
    {
        if (connection_aborted()) {
            return true;
        }
        $stopFile = defined('SCRAPER_STOP_FILE')
            ? SCRAPER_STOP_FILE
            : sys_get_temp_dir() . '/scraper_stop.flag';
        if (file_exists($stopFile)) {
            return true;
        }
        return false;
    }

    /**
     * Pausa con sleep aleatorio entre min y max.
     *
     * @param float $min Segundos mínimos
     * @param float $max Segundos máximos
     */
    public function delay(float $min, float $max): void
    {
        $sleep = mt_rand((int)($min * 100), (int)($max * 100)) / 100;
        $this->sendProgress([
            'type'    => 'wait',
            'message' => "⏳ Esperando {$sleep} s..."
        ]);
        usleep((int) ($sleep * 1_000_000));
    }

    /**
     * Limpia la señal de stop residual de ejecuciones anteriores.
     */
    public function clearStopSignal(): void
    {
        $stopFile = defined('SCRAPER_STOP_FILE')
            ? SCRAPER_STOP_FILE
            : sys_get_temp_dir() . '/scraper_stop.flag';
        @unlink($stopFile);
    }

    /**
     * Formatea bytes a representación legible.
     *
     * @param int $bytes Cantidad de bytes
     * @return string Ej: "1.5 MB"
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        return number_format($bytes / 1024, 2) . ' KB';
    }
}
