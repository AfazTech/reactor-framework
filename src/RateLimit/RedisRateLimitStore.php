<?php

namespace Reactor\RateLimit;

use Amp\Redis\RedisClient;

final class RedisRateLimitStore implements RateLimitStoreInterface
{
    public function __construct(private RedisClient $redis, private string $prefix = 'reactor:throttle:') {}
    public function hit(string $key, int $limit, int $seconds): RateLimitResult
    {
        if ($limit < 1 || $seconds < 1) { throw new \InvalidArgumentException('Limit and window must be positive.'); }
        [$count, $ttl] = $this->redis->eval(
            "local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('PEXPIRE',KEYS[1],ARGV[1]) end; return {n,redis.call('PTTL',KEYS[1])}",
            [$this->prefix . hash('sha256', $key)], [$seconds * 1000]
        );
        return new RateLimitResult($count <= $limit, max(0, $limit - $count), max(1, (int) ceil($ttl / 1000)));
    }
}
