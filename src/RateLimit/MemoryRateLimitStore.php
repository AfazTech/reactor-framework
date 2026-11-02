<?php

namespace Reactor\RateLimit;

/** Atomic within one event-loop process; bounded to avoid long-lived worker leaks. */
final class MemoryRateLimitStore implements RateLimitStoreInterface
{
    private array $entries = [];
    private float $nextCleanup = 0.0;
    public function __construct(private int $maxEntries = 10000, private ?\Closure $clock = null) { if ($maxEntries < 1) { throw new \InvalidArgumentException('Invalid capacity.'); } }
    public function hit(string $key, int $limit, int $seconds): RateLimitResult
    {
        if ($limit < 1 || $seconds < 1) { throw new \InvalidArgumentException('Limit and window must be positive.'); }
        $now = $this->clock !== null ? ($this->clock)() : microtime(true);
        if ($now >= $this->nextCleanup) {
            foreach ($this->entries as $existing => $entry) { if ($entry['expires'] <= $now) { unset($this->entries[$existing]); } }
            $this->nextCleanup = $now + 1;
        }
        if (!isset($this->entries[$key]) || $this->entries[$key]['expires'] <= $now) {
            // Fail closed at capacity rather than evicting an active user's limit.
            if (!isset($this->entries[$key]) && count($this->entries) >= $this->maxEntries) { return new RateLimitResult(false, 0, $seconds); }
            $this->entries[$key] = ['count' => 0, 'expires' => $now + $seconds];
        }
        $entry = &$this->entries[$key]; $entry['count']++;
        return new RateLimitResult($entry['count'] <= $limit, max(0, $limit - $entry['count']), max(1, (int) ceil($entry['expires'] - $now)));
    }
}
