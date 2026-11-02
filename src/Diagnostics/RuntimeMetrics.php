<?php

namespace Reactor\Diagnostics;

/** Bounded in-process aggregates; never uses user/chat IDs as metric labels. */
final class RuntimeMetrics
{
    private int $active = 0;
    private array $updates = [];
    private array $queries = ['total' => 0, 'failed' => 0, 'seconds' => 0.0];
    private float $lag = 0.0;
    private float $maxLag = 0.0;
    public function begin(): void { $this->active++; }
    public function finish(string $status, float $seconds): void
    {
        $this->active = max(0, $this->active - 1);
        if (!in_array($status, ['success', 'handler_error', 'error', 'fatal_error', 'invalid_callback',
            'throttled', 'stopped_by_middleware', 'built_in_fallback'], true)) { $status = 'other'; }
        $bucket = $this->updates[$status] ?? ['count' => 0, 'seconds' => 0.0, 'max_seconds' => 0.0];
        $bucket['count']++; $bucket['seconds'] += $seconds;
        $bucket['max_seconds'] = max($bucket['max_seconds'], $seconds);
        $this->updates[$status] = $bucket;
    }
    public function query(float $seconds, bool $failed): void
    {
        $this->queries['total']++; $this->queries['failed'] += (int) $failed;
        $this->queries['seconds'] += $seconds;
    }
    public function loopLag(float $seconds): void
    {
        $this->lag = max(0, $seconds); $this->maxLag = max($this->maxLag, $this->lag);
    }
    public function export(string $path): void
    {
        $directory = dirname($path);
        if (!\Amp\File\isDirectory($directory)) { \Amp\File\createDirectoryRecursively($directory); }
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            \Amp\File\write($temp, json_encode(['pid' => getmypid(), 'observed_at' => time()] + $this->snapshot(), JSON_THROW_ON_ERROR));
            \Amp\File\move($temp, $path);
        } finally {
            if (\Amp\File\exists($temp)) { \Amp\File\deleteFile($temp); }
        }
    }
    public function snapshot(): array
    {
        return ['active_updates' => $this->active, 'updates' => $this->updates, 'queries' => $this->queries,
            'loop_lag_seconds' => $this->lag, 'max_loop_lag_seconds' => $this->maxLag,
            'memory_bytes' => memory_get_usage(true), 'peak_memory_bytes' => memory_get_peak_usage(true)];
    }
}
