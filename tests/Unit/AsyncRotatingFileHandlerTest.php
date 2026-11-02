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

        $this->assertCount(3, $remaining);
        $this->assertFileDoesNotExist($this->tempDir . '/app-2020-01-03.log');
        $this->assertFileExists($this->tempDir . '/app-2020-01-04.log');
        $this->assertFileExists($this->tempDir . '/app-2020-01-05.log');
        $this->assertFileExists($this->tempDir . '/app-' . date('Y-m-d') . '.log');
        $handler->close();
    }

    public function testRetentionPreservesOtherPrefixesAndNonLogFiles(): void
    {
        file_put_contents($this->tempDir . '/app-2020-01-01.log', 'old');
        file_put_contents($this->tempDir . '/worker-2020-01-01.log', 'other logger');
        file_put_contents($this->tempDir . '/app-2020-01-01.log.bak', 'backup');

        $handler = new AsyncRotatingFileHandler($this->tempDir, 'app', 1);
        $logger = new MonologLogger('test', [$handler]);
        $logger->info('Current entry');
        $handler->close();

        $this->assertFileDoesNotExist($this->tempDir . '/app-2020-01-01.log');
        $this->assertFileExists($this->tempDir . '/worker-2020-01-01.log');
        $this->assertFileExists($this->tempDir . '/app-2020-01-01.log.bak');
        $this->assertStringContainsString('Current entry',
            file_get_contents($this->tempDir . '/app-' . date('Y-m-d') . '.log'));
    }
}
