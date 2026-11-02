<?php

namespace Reactor\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Throttle
{
    public function __construct(public int $limit = 5, public int $seconds = 10, public string $key = 'user')
    {
        if ($limit < 1 || $seconds < 1 || !in_array($key, ['user', 'chat', 'handler'], true)) {
            throw new \InvalidArgumentException('Invalid throttle limit, window or key.');
        }
    }
}
