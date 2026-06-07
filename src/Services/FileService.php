<?php

namespace ScrapApp\Services;

/**
 * Service for file-system operations.
 *
 * Handles directory size calculation and other file utilities.
 * Extracted from global functions in config.php.
 */
class FileService
{
    /**
     * Calculate the total size in bytes of a directory (recursive).
     *
     * @param string $dir Absolute path to the directory
     * @return int Total size in bytes
     */
    public function calculateDirectorySize(string $dir): int
    {
        $size = 0;

        if (!is_dir($dir)) {
            return $size;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    /**
     * Format bytes into a human-readable string.
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return number_format($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Ensure a directory exists, creating it recursively if needed.
     *
     * @param string $path
     * @param int $permissions
     * @return bool True if the directory exists or was created
     */
    public function ensureDirectoryExists(string $path, int $permissions = 0755): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, $permissions, true);
    }

    /**
     * Delete a directory and all its contents recursively.
     *
     * @param string $dir
     * @return bool
     */
    public function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }

        return @rmdir($dir);
    }
}
