<?php

namespace ScrapApp\Services;

/**
 * Service for image-related operations.
 *
 * Handles scanning directories for images and converting them to WebP.
 * Extracted from global functions in config.php.
 */
class ImageService
{
    /**
     * Allowed image extensions for scanning.
     */
    private array $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Extensions eligible for WebP conversion (already-webp skipped).
     */
    private array $convertibleExtensions = ['jpg', 'jpeg', 'png', 'gif'];

    /**
     * Scan a directory for image files, sorted naturally.
     *
     * @param string $dir Absolute path to the directory
     * @return array<string> Sorted array of full file paths
     */
    public function scanImages(string $dir): array
    {
        $files = [];

        if (!is_dir($dir)) {
            return $files;
        }

        $handle = opendir($dir);
        if ($handle === false) {
            return $files;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, $this->imageExtensions)) {
                    $files[] = $path;
                }
            }
        }
        closedir($handle);

        natsort($files);
        return array_values($files);
    }

    /**
     * Convert all convertible images in a directory to WebP format.
     *
     * Uses cwebp CLI (with LD_LIBRARY_PATH fallback for XAMPP/Apache)
     * writing to a temp file first to avoid UTF-8 path issues.
     *
     * @param string $dirPath Absolute path to the comic directory
     * @param int $quality WebP quality (1-100), default 85
     * @return array{converted: int, skipped: int, failed: int, bytes_original: int, bytes_webp: int, bytes_ahorrados: int}
     */
    public function convertToWebp(string $dirPath, int $quality = 85): array
    {
        $stats = [
            'converted'       => 0,
            'skipped'         => 0,
            'failed'          => 0,
            'bytes_original'  => 0,
            'bytes_webp'      => 0,
            'bytes_ahorrados' => 0,
        ];

        if (!is_dir($dirPath)) {
            return $stats;
        }

        $handle = opendir($dirPath);
        if ($handle === false) {
            return $stats;
        }

        $files = [];
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dirPath . '/' . $entry;
            if (is_file($path)) {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, $this->convertibleExtensions)) {
                    $files[] = $path;
                }
            }
        }
        closedir($handle);

        natsort($files);

        foreach ($files as $filepath) {
            $info = pathinfo($filepath);
            $filenameNoExt = $info['filename'];
            $webpPath = $info['dirname'] . '/' . $filenameNoExt . '.webp';

            // If WebP target already exists, consider it converted and delete original
            if (file_exists($webpPath)) {
                $stats['skipped']++;
                $origSize = @filesize($filepath);
                $webpSize = @filesize($webpPath);
                $stats['bytes_original'] += $origSize ?: 0;
                $stats['bytes_webp'] += $webpSize ?: 0;
                @unlink($filepath);
                continue;
            }

            $originalSize = @filesize($filepath);
            if ($originalSize === false || $originalSize === 0) {
                $stats['failed']++;
                continue;
            }

            $success = false;

            // Write to temp ASCII path first to avoid cwebp UTF-8 issues
            $tempWebp = sys_get_temp_dir() . '/' . uniqid('cwebp_', true) . '.webp';

            // Attempt 1: cwebp with LD_LIBRARY_PATH (for XAMPP/Apache)
            $cmd1 = sprintf(
                'LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu cwebp -q %d %s -o %s 2>/dev/null',
                $quality,
                escapeshellarg($filepath),
                escapeshellarg($tempWebp)
            );
            exec($cmd1, $out1, $ret1);

            // Attempt 2: cwebp without LD_LIBRARY_PATH (for CLI)
            if ($ret1 !== 0 || !file_exists($tempWebp) || filesize($tempWebp) === 0) {
                $cmd2 = sprintf(
                    'cwebp -q %d %s -o %s 2>/dev/null',
                    $quality,
                    escapeshellarg($filepath),
                    escapeshellarg($tempWebp)
                );
                exec($cmd2, $out2, $ret2);

                if (($ret2 !== 0 || !file_exists($tempWebp) || filesize($tempWebp) === 0)) {
                    if (file_exists($tempWebp)) {
                        @unlink($tempWebp);
                    }
                }
            }

            // Copy from temp to final destination
            if (file_exists($tempWebp) && filesize($tempWebp) > 0) {
                $webpData = @file_get_contents($tempWebp);
                if ($webpData !== false) {
                    $written = @file_put_contents($webpPath, $webpData);
                    if ($written !== false && file_exists($webpPath) && filesize($webpPath) > 0) {
                        $success = true;
                    }
                }
                @unlink($tempWebp);
            }

            if ($success) {
                $webpSize = @filesize($webpPath) ?: 0;
                $stats['converted']++;
                $stats['bytes_original'] += $originalSize;
                $stats['bytes_webp'] += $webpSize;
                @unlink($filepath);
            } else {
                $stats['failed']++;
                if (file_exists($webpPath)) {
                    @unlink($webpPath);
                }
            }
        }

        $stats['bytes_ahorrados'] = $stats['bytes_original'] - $stats['bytes_webp'];

        return $stats;
    }

    /**
     * Get the list of allowed image extensions.
     */
    public function getImageExtensions(): array
    {
        return $this->imageExtensions;
    }
}
