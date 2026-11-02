<?php

namespace Tests\Unit;

use Amp\Future;
use PHPUnit\Framework\TestCase;
use Reactor\Contracts\AsyncDatabaseManagerInterface;
use Reactor\Diagnostics\MeasuredDatabase;
use Reactor\Diagnostics\RuntimeMetrics;

class DiagnosticsTest extends TestCase
{
    public function testMeasuredQueriesAndUpdateOutcomesAreCounted(): void
    {
        $metrics = new RuntimeMetrics();
        $raw = $this->createMock(AsyncDatabaseManagerInterface::class);
        $raw->method('fetchOne')->willReturn(Future::complete(['value' => 1]));
        $raw->method('execute')->willReturn(Future::error(new \RuntimeException('failed')));
        $db = new MeasuredDatabase($raw, $metrics);
        self::assertSame(['value' => 1], $db->fetchOne('SELECT 1')->await());
        try { $db->execute('bad')->await(); } catch (\RuntimeException) {}
        $metrics->begin(); $metrics->finish('success', 0.01); $metrics->loopLag(0.005);
        $snapshot = $metrics->snapshot();
        self::assertSame(2, $snapshot['queries']['total']); self::assertSame(1, $snapshot['queries']['failed']);
        self::assertSame(1, $snapshot['updates']['success']['count']); self::assertSame(0, $snapshot['active_updates']);
        self::assertSame(0.005, $snapshot['loop_lag_seconds']);
    }
}
