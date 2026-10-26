<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Cache\Drivers\ArrayCache;
use Reactor\Cache\Drivers\FileCache;

class CacheDriversAsyncTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reactor_cache_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    /** @test */
    public function array_cache_reports_cached_null_as_present(): void
    {
        $cache = new ArrayCache();
        $cache->set('null_key', null)->await();

        $this->assertTrue($cache->has('null_key')->await());
        $this->assertNull($cache->get('null_key', 'fallback')->await());
    }

    /** @test */
    public function array_cache_remember_does_not_recompute_cached_null(): void
    {
        $cache = new ArrayCache();
        $calls = 0;

        $callback = function () use (&$calls) {
            $calls++;
            return null;
        };

        $cache->remember('null', 60, $callback)->await();
        $cache->remember('null', 60, $callback)->await();

        $this->assertSame(1, $calls);
    }

    /** @test */
    public function array_cache_increment_preserves_ttl(): void
    {
        $cache = new ArrayCache();
        $cache->set('counter', 1, 1)->await();
        $cache->increment('counter')->await();

        $this->assertSame(2, $cache->get('counter')->await());

        sleep(2);

        $this->assertNull($cache->get('counter')->await());
    }

    /** @test */
    public function file_cache_sets_and_gets_values(): void
    {
        $cache = new FileCache($this->tempDir, 'test_');

        $cache->set('foo', 'bar')->await();

        $this->assertSame('bar', $cache->get('foo')->await());
        $this->assertTrue($cache->has('foo')->await());
    }

    /** @test */
    public function file_cache_respects_ttl_in_has_and_get(): void
    {
        $cache = new FileCache($this->tempDir, 'test_');

        $cache->set('short', 'value', 1)->await();

        $this->assertTrue($cache->has('short')->await());
        $this->assertSame('value', $cache->get('short')->await());

        sleep(2);

        $this->assertFalse($cache->has('short')->await());
        $this->assertNull($cache->get('short')->await());
    }

    /** @test */
    public function file_cache_reports_cached_null_as_present(): void
    {
        $cache = new FileCache($this->tempDir, 'test_');
        $cache->set('null_key', null)->await();

        $this->assertTrue($cache->has('null_key')->await());
        $this->assertNull($cache->get('null_key', 'fallback')->await());
    }

    /** @test */
    public function file_cache_remember_does_not_recompute_cached_null(): void
    {
        $cache = new FileCache($this->tempDir, 'test_');
        $calls = 0;

        $callback = function () use (&$calls) {
            $calls++;
            return null;
        };

        $cache->remember('null', 60, $callback)->await();
        $cache->remember('null', 60, $callback)->await();

        $this->assertSame(1, $calls);
    }

    /** @test */
    public function file_cache_increment_and_decrement(): void
    {
        $cache = new FileCache($this->tempDir, 'test_');
        $cache->set('counter', 10)->await();

        $this->assertSame(11, $cache->increment('counter')->await());
        $this->assertSame(10, $cache->decrement('counter')->await());
    }

    /** @test */
    public function file_cache_clear_removes_only_prefixed_files(): void
    {
        $cache = new FileCache($this->tempDir, 'prefixed_');

        $cache->set('a', 1)->await();
        $cache->set('b', 2)->await();

        $unrelated = $this->tempDir . '/unrelated.cache';
        file_put_contents($unrelated, 'keep');

        $cache->clear()->await();

        $this->assertFalse($cache->has('a')->await());
        $this->assertFalse($cache->has('b')->await());
        $this->assertFileExists($unrelated);

        @unlink($unrelated);
    }

    /** @test */
    public function file_cache_increment_preserves_ttl(): void
    {
        $cache = new FileCache($this->tempDir, 'test_');
        $cache->set('counter', 1, 1)->await();
        $cache->increment('counter')->await();

        $this->assertSame(2, $cache->get('counter')->await());

        sleep(2);

        $this->assertNull($cache->get('counter')->await());
    }
}
