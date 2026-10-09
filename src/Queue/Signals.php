<?php
namespace Reactor\Queue;

/**
 * File-based restart signal for queue workers.
 *
 * queue:restart writes the current timestamp into the restart file.
 * Running workers compare the file mtime against their own start time
 * and exit gracefully when a newer signal is present.
 */
class Signals
{
    public function __construct(private string $restartFile)
    {
    }

    public function shouldRestart(int $workerStartedAt): bool
    {
        if (!file_exists($this->restartFile)) {
            return false;
        }

        $mtime = filemtime($this->restartFile);

        return $mtime !== false && $mtime >= $workerStartedAt;
    }

    public function restart(): void
    {
        $dir = dirname($this->restartFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents($this->restartFile, (string) time());
    }

    public function clear(): void
    {
        if (file_exists($this->restartFile)) {
            @unlink($this->restartFile);
        }
    }
}
