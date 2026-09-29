<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Cache\CacheManager;
use Reactor\Cache\Drivers\ArrayCache;

class CacheManagerTest extends TestCase
{
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    /** @test */
    public function it_sets_and_gets_values()
    {
        $this->cache->set('key', 'value');
        $this->assertEquals('value', $this->cache->get('key'));

        $this->cache->set('array', ['a' => 1, 'b' => 2]);
        $this->assertEquals(['a' => 1, 'b' => 2], $this->cache->get('array'));
    }

    /** @test */
    public function it_returns_default_when_key_not_found()
    {
        $this->assertNull($this->cache->get('non_existent'));
        $this->assertEquals('default', $this->cache->get('non_existent', 'default'));
    }

    /** @test */
    public function it_checks_if_key_exists()
    {
        $this->assertFalse($this->cache->has('key'));
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->has('key'));
    }

    /** @test */
    public function it_deletes_values()
    {
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->has('key'));

        $this->cache->delete('key');
        $this->assertFalse($this->cache->has('key'));
    }

    /** @test */
    public function it_clears_all_values()
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $this->assertTrue($this->cache->has('key1'));
        $this->assertTrue($this->cache->has('key2'));

        $this->cache->clear();
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    /** @test */
    public function it_remembers_values_with_ttl()
    {
        $called = 0;
        $callback = function () use (&$called) {
            $called++;
            return 'generated';
        };

        // First call: generate and store
        $result1 = $this->cache->remember('key', 60, $callback);
        $this->assertEquals('generated', $result1);
        $this->assertEquals(1, $called);

        // Second call: retrieve from cache
        $result2 = $this->cache->remember('key', 60, $callback);
        $this->assertEquals('generated', $result2);
        $this->assertEquals(1, $called);
    }

    /** @test */
    public function it_remembers_values_forever()
    {
        $called = 0;
        $callback = function () use (&$called) {
            $called++;
            return 'forever';
        };

        $this->cache->rememberForever('key', $callback);
        $this->assertEquals(1, $called);

        $this->cache->rememberForever('key', $callback);
        $this->assertEquals(1, $called);
    }

    /** @test */
    public function it_increments_and_decrements_values()
    {
        $this->cache->set('counter', 10);

        $this->assertEquals(11, $this->cache->increment('counter'));
        $this->assertEquals(11, $this->cache->get('counter'));

        // decrement should return 10 (since 11 - 1 = 10)
        $this->assertEquals(10, $this->cache->decrement('counter'));
        $this->assertEquals(10, $this->cache->get('counter'));

        // increment by 5: 10 + 5 = 15
        $this->assertEquals(15, $this->cache->increment('counter', 5));
        $this->assertEquals(15, $this->cache->get('counter'));

        // decrement by 5: 15 - 5 = 10
        $this->assertEquals(10, $this->cache->decrement('counter', 5));
        $this->assertEquals(10, $this->cache->get('counter'));
    }

    /** @test */
    public function it_starts_increment_from_zero_if_key_not_exists()
    {
        $this->assertEquals(1, $this->cache->increment('new_counter'));
        $this->assertEquals(1, $this->cache->get('new_counter'));
    }

    /** @test */
    public function it_expires_values_after_ttl()
    {
        $this->cache->set('expiring', 'value', 1);

        // Immediately available
        $this->assertEquals('value', $this->cache->get('expiring'));

        // Manually expire it
        $reflection = new \ReflectionClass($this->cache);
        $prop = $reflection->getProperty('expirations');
        $prop->setAccessible(true);
        $expirations = $prop->getValue($this->cache);
        $expirations['expiring'] = time() - 1;
        $prop->setValue($this->cache, $expirations);

        $this->assertNull($this->cache->get('expiring'));
        $this->assertFalse($this->cache->has('expiring'));
    }

    /** @test */
    public function cache_manager_can_use_array_driver()
    {
        $config = ['driver' => 'array'];
        $manager = new CacheManager($config);

        $manager->set('test', 'value');
        $this->assertEquals('value', $manager->get('test'));
        $this->assertTrue($manager->has('test'));
        $manager->delete('test');
        $this->assertFalse($manager->has('test'));
    }
}
