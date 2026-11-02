<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Attributes\Throttle;
use Reactor\RateLimit\MemoryRateLimitStore;
use Reactor\RateLimit\ThrottleGuard;
use Reactor\Testing\UpdateFactory;

class ThrottleTest extends TestCase
{
    public function testWindowExpiryAndIndependentUsers(): void
    {
        $now = 100.0;
        $store = new MemoryRateLimitStore(10, function () use (&$now) { return $now; });
        $guard = new ThrottleGuard($store); $rule = ['limit' => 2, 'seconds' => 10, 'key' => 'user'];
        self::assertTrue($guard->check('handler', UpdateFactory::message(1, 'x'), $rule)->allowed);
        self::assertTrue($guard->check('handler', UpdateFactory::message(1, 'x'), $rule)->allowed);
        self::assertFalse($guard->check('handler', UpdateFactory::message(1, 'x'), $rule)->allowed);
        self::assertTrue($guard->check('handler', UpdateFactory::message(2, 'x'), $rule)->allowed);
        $now = 111.0;
        self::assertTrue($guard->check('handler', UpdateFactory::message(1, 'x'), $rule)->allowed);
    }
    public function testCapacityFailsClosedAndInvalidRulesAreRejected(): void
    {
        $store = new MemoryRateLimitStore(1);
        self::assertTrue($store->hit('a', 1, 10)->allowed);
        self::assertFalse($store->hit('b', 1, 10)->allowed);
        self::assertFalse($store->hit('a', 1, 10)->allowed);
        $this->expectException(\InvalidArgumentException::class); new Throttle(0);
    }
}
