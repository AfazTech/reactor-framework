<?php

namespace Reactor\RateLimit;

final class RateLimitResult
{
    public function __construct(public readonly bool $allowed, public readonly int $remaining, public readonly int $retryAfter) {}
}
