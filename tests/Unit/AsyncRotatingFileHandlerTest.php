<?php

namespace Tests\Unit;

use Monolog\Level;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;
use Reactor\Core\Logging\AsyncRotatingFileHandler;

class AsyncRotatingFileHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reactor_log_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);
    }

    /** @test */
    public function it_writes_records_to_a_dated_file(): void
    {
        $handler = new AsyncRotatingFileHandler(
            directory: $this->tempDir,
            filenamePrefix: 'app',
            maxFiles: 30,
            level: Level::Debug,
        );

        $logger = new MonologLogger('test', [$handler]);
        $logger->info('Hello, world!');

        $file = $this->tempDir . '/app-' . date('Y-m-d') . '.log';

        $this->assertFileExists($file);
        $this->assertStringContainsString('Hello, world!', (string) file_get_contents($file));
    }

    /** @test */
    public function it_respects_the_maximum_number_of_files(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $old = $this->tempDir . '/app-2020-01-0' . ($i + 1) . '.log';
            file_put_contents($old, 'old');
        }

        $handler = new AsyncRotatingFileHandler(
            directory: $this->tempDir,
            filenamePrefix: 'app',
            maxFiles: 3,
            level: Level::Debug,
        );

        $logger = new MonologLogger('test', [$handler]);
        $logger->info('New entry');

        $remaining = glob($this->tempDir . '/app-*.log') ?: [];

        $this->assertLessThanOrEqual(3, count($remaining));
    }
}
