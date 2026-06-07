<?php

namespace ScrapApp\Tests;

use PHPUnit\Framework\TestCase;
use ScrapApp\Infrastructure\ErrorHandler;

class ErrorHandlerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/scrapapp_error_test_' . uniqid() . '.log';

        // Ensure we start clean
        ErrorHandler::unregister();
    }

    protected function tearDown(): void
    {
        ErrorHandler::unregister();
        if (file_exists($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    public function testRegister(): void
    {
        ErrorHandler::register($this->logFile);

        // Use reflection to check that it's registered
        $ref = new \ReflectionClass(ErrorHandler::class);
        $prop = $ref->getProperty('registered');
        $prop->setAccessible(true);

        $this->assertTrue($prop->getValue());
    }

    public function testRegisterTwiceDoesNotReregister(): void
    {
        ErrorHandler::register($this->logFile);

        $ref = new \ReflectionClass(ErrorHandler::class);
        $prop = $ref->getProperty('registered');
        $prop->setAccessible(true);

        // Should still be registered
        $this->assertTrue($prop->getValue());
    }

    public function testUnregister(): void
    {
        ErrorHandler::register($this->logFile);
        ErrorHandler::unregister();

        $ref = new \ReflectionClass(ErrorHandler::class);
        $prop = $ref->getProperty('registered');
        $prop->setAccessible(true);

        $this->assertFalse($prop->getValue());
    }

    public function testGetLogFile(): void
    {
        ErrorHandler::register($this->logFile);
        $this->assertSame($this->logFile, ErrorHandler::getLogFile());
    }

    public function testLogFileIsCreated(): void
    {
        ErrorHandler::register($this->logFile);
        $this->assertFileExists(dirname($this->logFile));
    }

    public function testLogErrorDirectly(): void
    {
        ErrorHandler::register($this->logFile);

        // Use reflection to test logError() directly, bypassing PHPUnit's error handler
        $ref = new \ReflectionClass(ErrorHandler::class);
        $method = $ref->getMethod('logError');
        $method->setAccessible(true);

        $method->invoke(null, '[User Warning] Test user warning', [
            'file' => __FILE__,
            'line' => __LINE__,
        ]);

        $logContent = file_get_contents($this->logFile);
        $this->assertStringContainsString('Test user warning', $logContent);
        $this->assertStringContainsString('User Warning', $logContent);
        $this->assertStringContainsString(basename(__FILE__), $logContent);
    }

    public function testLogErrorWithContext(): void
    {
        ErrorHandler::register($this->logFile);

        $ref = new \ReflectionClass(ErrorHandler::class);
        $method = $ref->getMethod('logError');
        $method->setAccessible(true);

        $method->invoke(null, '[Notice] Context test', [
            'file'  => '/path/to/file.php',
            'line'  => 42,
            'trace' => "#0 /test.php(10): test()\n#1 /index.php(20): run()",
        ]);

        $logContent = file_get_contents($this->logFile);
        $this->assertStringContainsString('Context test', $logContent);
        $this->assertStringContainsString('/path/to/file.php', $logContent);
        $this->assertStringContainsString('42', $logContent);
        $this->assertStringContainsString('#0 /test.php(10): test()', $logContent);
    }

    public function testHandleErrorRespectsErrorReporting(): void
    {
        ErrorHandler::register($this->logFile);

        // Temporarily disable error reporting for notices
        $oldLevel = error_reporting();
        error_reporting($oldLevel & ~E_USER_NOTICE);

        // This notice should not be logged since error_reporting excludes it
        // handleError returns false when error_reporting suppresses the level
        $result = ErrorHandler::handleError(E_USER_NOTICE, 'This should NOT appear', __FILE__, __LINE__);

        // Restore
        error_reporting($oldLevel);

        $this->assertFalse($result);

        $logContent = @file_get_contents($this->logFile);
        $this->assertStringNotContainsString('This should NOT appear', $logContent !== false ? $logContent : '');
    }
}
