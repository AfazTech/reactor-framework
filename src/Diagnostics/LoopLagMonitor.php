<?php

namespace Reactor\Diagnostics;

use Revolt\EventLoop;

final class LoopLagMonitor
{
    private ?string $watcher = null;
    public function __construct(private RuntimeMetrics $metrics, private ?string $snapshotPath = null) {}
    public function start(float $interval = 1.0): void
    {
        if ($interval <= 0) { throw new \InvalidArgumentException('Interval must be positive.'); }
        if ($this->watcher !== null) { return; }
        $exporting = false;
        $expected = hrtime(true) / 1e9 + $interval;
        $this->watcher = EventLoop::repeat($interval, function () use (&$expected, &$exporting, $interval) {
            $now = hrtime(true) / 1e9;
            $this->metrics->loopLag($now - $expected);
            $expected = $now + $interval;
            if ($this->snapshotPath !== null && !$exporting) {
                $exporting = true;
                try { $this->metrics->export($this->snapshotPath); }
                catch (\Throwable) { /* Diagnostics must not crash polling. Doctor reports storage issues. */ }
                finally { $exporting = false; }
            }
        });
        EventLoop::unreference($this->watcher);
    }
    public function stop(): void
    {
        if ($this->watcher !== null) { EventLoop::cancel($this->watcher); $this->watcher = null; }
    }
    public function __destruct() { $this->stop(); }
}
