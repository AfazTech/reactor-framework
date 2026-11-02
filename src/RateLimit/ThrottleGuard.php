<?php

namespace Reactor\RateLimit;

use Reactor\Core\Traits\FromIdExtractor;

final class ThrottleGuard
{
    use FromIdExtractor;
    public function __construct(private RateLimitStoreInterface $store) {}
    public function check(string $handler, array $update, array $rule): RateLimitResult
    {
        $identity = match ($rule['key']) {
            'chat' => $this->extractChatId($update),
            'handler' => 'all',
            default => $this->fromId($update),
        };
        return $this->store->hit($handler . ':' . ($identity ?? 'unknown'), $rule['limit'], $rule['seconds']);
    }
}
