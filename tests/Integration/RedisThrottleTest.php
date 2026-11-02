<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Reactor\RateLimit\RedisRateLimitStore;
use function Amp\Redis\createRedisClient;

class RedisThrottleTest extends TestCase
{
    public function testConcurrentIndependentClientsShareAnAtomicLimitAndExpiry(): void
    {
        $uri = getenv('REACTOR_TEST_REDIS_URI');
        if (!$uri) { self::markTestSkipped('Set REACTOR_TEST_REDIS_URI to run Redis integration tests.'); }
        $prefix = 'reactor:test:' . bin2hex(random_bytes(8)) . ':';
        $stores = [new RedisRateLimitStore(createRedisClient($uri), $prefix), new RedisRateLimitStore(createRedisClient($uri), $prefix)];
        $requests = [];
        for ($i = 0; $i < 20; $i++) {
            $store = $stores[$i % 2];
            $requests[] = \Amp\async(fn () => $store->hit('same-user', 5, 1));
        }
        $results = \Amp\Future\await($requests);
        self::assertCount(5, array_filter($results, fn ($r) => $r->allowed));
        self::assertCount(15, array_filter($results, fn ($r) => !$r->allowed));
        self::assertTrue($stores[0]->hit('different-user', 5, 1)->allowed);
        \Amp\delay(1.05);
        self::assertTrue($stores[1]->hit('same-user', 5, 1)->allowed);
    }
}
