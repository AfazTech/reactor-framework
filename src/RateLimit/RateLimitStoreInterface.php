<?php

namespace Reactor\RateLimit;

interface RateLimitStoreInterface
{
    public function hit(string $key, int $limit, int $seconds): RateLimitResult;
}
