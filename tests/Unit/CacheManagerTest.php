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
        $this->cache->set('key', 'value')->await();
        $this->assertEquals('value', $this->cache->get('key')->await());

        $this->cache->set('array', ['a' => 1, 'b' => 2])->await();
        $this->assertEquals(['a' => 1, 'b' => 2], $this->cache->get('array')->await());
    }

    /** @test */
    public function it_returns_default_when_key_not_found()
    {
        $this->assertNull($this->cache->get('non_existent')->await());
        $this->assertEquals('default', $this->cache->get('non_existent', 'default')->await());
    }

    /** @test */
    public function it_checks_if_key_exists()
    {
        $this->assertFalse($this->cache->has('key')->await());
        $this->cache->set('key', 'value')->await();
        $this->assertTrue($this->cache->has('key')->await());
    }

    /** @test */
    public function it_detects_a_cached_null_value_as_present()
    {
        $this->cache->set('null_key', null)->await();
        $this->assertTrue($this->cache->has('null_key')->await());
        $this->assertNull($this->cache->get('null_key', 'fallback')->await());
    }

    /** @test */
    public function it_deletes_values()
    {
        $this->cache->set('key', 'value')->await();
        $this->assertTrue($this->cache->has('key')->await());

        $this->cache->delete('key')->await();
        $this->assertFalse($this->cache->has('key')->await());
    }

    /** @test */
    public function it_clears_all_values()
    {
        $this->cache->set('key1', 'value1')->await();
        $this->cache->set('key2', 'value2')->await();

        $this->cache->clear()->await();

        $this->assertFalse($this->cache->has('key1')->await());
        $this->assertFalse($this->cache->has('key2')->await());
    }

    /** @test */
    public function it_remembers_values_with_ttl()
    {
        $called = 0;
        $callback = function () use (&$called) {
            $called++;
            return 'generated';
        };

        $result1 = $this->cache->remember('key', 60, $callback)->await();
        $this->assertEquals('generated', $result1);
        $this->assertEquals(1, $called);

        $result2 = $this->cache->remember('key', 60, $callback)->await();
        $this->assertEquals('generated', $result2);
        $this->assertEquals(1, $called);
    }

    /** @test */
    public function it_remembers_a_null_value_without_recomputing()
    {
        $called = 0;
        $callback = function () use (&$called) {
            $called++;
            return null;
        };

        $this->cache->remember('null_forever', 60, $callback)->await();
        $this->cache->remember('null_forever', 60, $callback)->await();

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

        $this->cache->rememberForever('key', $callback)->await();
        $this->cache->rememberForever('key', $callback)->await();

        $this->assertEquals(1, $called);
    }

    /** @test */
    public function it_increments_and_decrements_values()
    {
        $this->cache->set('counter', 10)->await();

        $this->assertEquals(11, $this->cache->increment('counter')->await());
        $this->assertEquals(11, $this->cache->get('counter')->await());

        $this->assertEquals(10, $this->cache->decrement('counter')->await());
        $this->assertEquals(10, $this->cache->get('counter')->await());

        $this->assertEquals(15, $this->cache->increment('counter', 5)->await());
        $this->assertEquals(15, $this->cache->get('counter')->await());

        $this->assertEquals(10, $this->cache->decrement('counter', 5)->await());
        $this->assertEquals(10, $this->cache->get('counter')->await());
    }

    /** @test */
    public function it_preserves_ttl_when_incrementing()
    {
        $this->cache->set('ttl_counter', 1, 60)->await();
        $this->cache->increment('ttl_counter')->await();

        $this->assertEquals(2, $this->cache->get('ttl_counter')->await());

        // Manually expire the key to verify that the TTL was preserved
        // and not silently removed by increment().
        $reflection = new \ReflectionClass($this->cache);
        $prop = $reflection->getProperty('expirations');
        $prop->setAccessible(true);
        $expirations = $prop->getValue($this->cache);
        $expirations['ttl_counter'] = time() - 1;
        $prop->setValue($this->cache, $expirations);

        $this->assertNull($this->cache->get('ttl_counter')->await());
    }

    /** @test */
    public function it_starts_increment_from_zero_if_key_not_exists()
    {
        $this->assertEquals(1, $this->cache->increment('new_counter')->await());
        $this->assertEquals(1, $this->cache->get('new_counter')->await());
    }

    /** @test */
    public function cache_manager_can_use_array_driver()
    {
        $config = ['driver' => 'array'];
        $manager = new CacheManager($config);

        $manager->set('test', 'value')->await();
        $this->assertEquals('value', $manager->get('test')->await());
        $this->assertTrue($manager->has('test')->await());
        $manager->delete('test')->await();
        $this->assertFalse($manager->has('test')->await());
    }
}
