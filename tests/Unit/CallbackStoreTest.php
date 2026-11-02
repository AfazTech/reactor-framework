<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Cache\Drivers\ArrayCache;
use Reactor\Callbacks\CallbackStore;

class CallbackStoreTest extends TestCase
{
    public function testOpaquePayloadIsShortBoundToUserAndRevocable(): void
    {
        $cache = new ArrayCache(); $store = new CallbackStore($cache);
        $token = $store->issue('order.view', ['id' => 42, 'details' => str_repeat('x', 500)], 10);
        self::assertLessThanOrEqual(64, strlen($token));
        self::assertNull($store->resolve($token, 11));
        self::assertSame(42, $store->resolve($token, 10)['data']['id']);
        self::assertNull((new CallbackStore($cache, 'other-bot'))->resolve($token, 10));
        $store->revoke($token); self::assertNull($store->resolve($token, 10));
    }
    public function testExpiredAndMalformedTokensAreRejected(): void
    {
        $cache = new ArrayCache(); $store = new CallbackStore($cache);
        $token = 'rcb:' . str_repeat('a', 32);
        $cache->set('default:' . $token, ['action' => 'test', 'data' => [], 'user' => null, 'expires' => time() - 1])->await();
        self::assertNull($store->resolve($token, 1)); self::assertNull($store->resolve('rcb:invalid', 1));
    }
}
