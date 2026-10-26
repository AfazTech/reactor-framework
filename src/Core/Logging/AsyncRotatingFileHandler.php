<?php

namespace Reactor\Core\Logging;

use Amp\File;
use Amp\File\File;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use function Amp\async;

/**
 * Non-blocking daily rotating file handler backed by amphp/file.
 *
 * Mirrors Monolog's RotatingFileHandler daily rotation behaviour
 * (filename-YYYY-MM-DD.log, keep the last N files) without blocking
 * the event loop while writing.
 *
 * Each write runs inside a fiber so that the Amp event loop remains
 * free to process other work while the file system operation is
 * pending. The public Logger API stays synchronous: the caller waits
 * for the write to complete, but the event loop does not.
 */
final class AsyncRotatingFileHandler extends AbstractProcessingHandler
{
    private ?File $file = null;
    private string $currentDate;

    public function __construct(
        private readonly string $directory,
        private readonly string $filenamePrefix,
        private readonly int $maxFiles = 30,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
        $this->currentDate = date('Y-m-d');
    }

    protected function write(array|LogRecord $record): void
    {
        $formatted = is_array($record) ? $record['formatted'] : $record->formatted;

        if (!is_string($formatted)) {
            throw new \ValueError(
                'AsyncRotatingFileHandler only supports string formatted log records.'
            );
        }

        async(function () use ($formatted): void {
            $this->ensureFile();
            $this->file->write($formatted);
        })->await();
    }

    public function close(): void
    {
        if ($this->file !== null) {
            $this->file->close();
            $this->file = null;
        }

        parent::close();
    }

    private function ensureFile(): void
    {
        $today = date('Y-m-d');

        if ($this->file !== null && $today === $this->currentDate) {
            return;
        }

        if ($this->file !== null) {
            $this->file->close();
        }

        if (!File\isDirectory($this->directory)) {
            File\createDirectoryRecursively($this->directory, 0755);
        }

        $this->currentDate = $today;

        $path = $this->directory . '/' . $this->filenamePrefix . '-' . $today . '.log';
        $this->file = File\openFile($path, 'a');

        $this->pruneOldFiles();
    }

    private function pruneOldFiles(): void
    {
        $pattern = $this->directory . '/' . $this->filenamePrefix . '-*.log';
        $files = glob($pattern);

        if ($files === false || count($files) <= $this->maxFiles) {
            return;
        }

        sort($files);

        $remove = array_slice($files, 0, count($files) - $this->maxFiles);

        foreach ($remove as $file) {
            @unlink($file);
        }
    }
}
