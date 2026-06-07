<?php

namespace ScrapApp\Tests;

use PHPUnit\Framework\TestCase;
use ScrapApp\Services\ImageService;

class ImageServiceTest extends TestCase
{
    private ImageService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->service = new ImageService();
        $this->tmpDir  = sys_get_temp_dir() . '/scrapapp_img_test_' . uniqid();
        @mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    public function testGetImageExtensions(): void
    {
        $extensions = $this->service->getImageExtensions();

        $this->assertIsArray($extensions);
        $this->assertContains('jpg', $extensions);
        $this->assertContains('jpeg', $extensions);
        $this->assertContains('png', $extensions);
        $this->assertContains('gif', $extensions);
        $this->assertContains('webp', $extensions);
    }

    public function testScanImagesEmptyDirectory(): void
    {
        $result = $this->service->scanImages($this->tmpDir);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testScanImagesWithFiles(): void
    {
        touch($this->tmpDir . '/image1.jpg');
        touch($this->tmpDir . '/image2.png');
        touch($this->tmpDir . '/image3.gif');
        touch($this->tmpDir . '/not_an_image.txt');
        touch($this->tmpDir . '/image4.webp');

        $result = $this->service->scanImages($this->tmpDir);

        $this->assertCount(4, $result);
        // scanImages returns full paths
        $this->assertContains($this->tmpDir . '/image1.jpg', $result);
        $this->assertContains($this->tmpDir . '/image2.png', $result);
        $this->assertContains($this->tmpDir . '/image3.gif', $result);
        $this->assertContains($this->tmpDir . '/image4.webp', $result);
        $this->assertNotContains($this->tmpDir . '/not_an_image.txt', $result);
    }

    public function testScanImagesReturnsSorted(): void
    {
        touch($this->tmpDir . '/page_3.jpg');
        touch($this->tmpDir . '/page_1.jpg');
        touch($this->tmpDir . '/page_2.jpg');

        $result = $this->service->scanImages($this->tmpDir);

        $this->assertCount(3, $result);
        // scanImages returns full paths, sorted naturally
        $this->assertSame($this->tmpDir . '/page_1.jpg', $result[0]);
        $this->assertSame($this->tmpDir . '/page_2.jpg', $result[1]);
        $this->assertSame($this->tmpDir . '/page_3.jpg', $result[2]);
    }

    public function testScanImagesNonExistentDirectory(): void
    {
        $result = $this->service->scanImages($this->tmpDir . '/nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testScanImagesWithSubdirectories(): void
    {
        touch($this->tmpDir . '/root.jpg');
        $subDir = $this->tmpDir . '/sub';
        mkdir($subDir);
        touch($subDir . '/nested.png');

        // scanImages is non-recursive (only scans given directory)
        $result = $this->service->scanImages($this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertContains($this->tmpDir . '/root.jpg', $result);
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
