<?php

namespace ScrapApp\Tests;

use PHPUnit\Framework\TestCase;
use ScrapApp\Services\FileService;

class FileServiceTest extends TestCase
{
    private FileService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->service = new FileService();
        $this->tmpDir  = sys_get_temp_dir() . '/scrapapp_test_' . uniqid();
        @mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    public function testFormatBytes(): void
    {
        $this->assertSame('0.00 B', $this->service->formatBytes(0));
        $this->assertSame('1.00 KB', $this->service->formatBytes(1024));
        $this->assertSame('1.00 MB', $this->service->formatBytes(1048576));
        $this->assertSame('1.00 GB', $this->service->formatBytes(1073741824));
        $this->assertSame('512.00 B', $this->service->formatBytes(512));
        $this->assertSame('2.50 KB', $this->service->formatBytes(2560));
    }

    public function testFormatBytesWithCustomPrecision(): void
    {
        $this->assertSame('1.50 KB', $this->service->formatBytes(1536));
    }

    public function testEnsureDirectoryExists(): void
    {
        $path = $this->tmpDir . '/nested/deep/dir';
        $this->assertTrue($this->service->ensureDirectoryExists($path));
        $this->assertDirectoryExists($path);
    }

    public function testEnsureDirectoryExistsReturnsTrueForExistingDir(): void
    {
        $this->assertTrue($this->service->ensureDirectoryExists($this->tmpDir));
    }

    public function testCalculateDirectorySizeEmpty(): void
    {
        $this->assertSame(0, $this->service->calculateDirectorySize($this->tmpDir));
    }

    public function testCalculateDirectorySizeWithFiles(): void
    {
        file_put_contents($this->tmpDir . '/file1.txt', str_repeat('A', 100));
        file_put_contents($this->tmpDir . '/file2.txt', str_repeat('B', 200));

        $this->assertSame(300, $this->service->calculateDirectorySize($this->tmpDir));
    }

    public function testCalculateDirectorySizeWithSubdirectories(): void
    {
        file_put_contents($this->tmpDir . '/root.txt', str_repeat('A', 50));

        $subDir = $this->tmpDir . '/sub';
        mkdir($subDir);
        file_put_contents($subDir . '/nested.txt', str_repeat('B', 150));

        $this->assertSame(200, $this->service->calculateDirectorySize($this->tmpDir));
    }

    public function testDeleteDirectory(): void
    {
        $dir = $this->tmpDir . '/delete_me';
        mkdir($dir);
        file_put_contents($dir . '/a.txt', 'test');
        mkdir($dir . '/inner');
        file_put_contents($dir . '/inner/b.txt', 'test');

        $this->assertTrue($this->service->deleteDirectory($dir));
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testDeleteDirectoryNonExistent(): void
    {
        $this->assertFalse($this->service->deleteDirectory($this->tmpDir . '/nonexistent'));
    }

    /**
     * Recursively delete a directory (cleanup helper).
     */
    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($dir);
    }
}
