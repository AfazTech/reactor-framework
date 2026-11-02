<?php

namespace Reactor\Core\Providers;

use Reactor\Callbacks\CallbackStore;
use Reactor\Cache\CacheManager;
use Reactor\Contracts\CacheInterface;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Conversation\ConversationManager;
use Reactor\Core\Config;
use Reactor\Core\Container;
use Reactor\Core\Paths;
use Reactor\Core\UpdateSequencer;
use Reactor\Diagnostics\LoopLagMonitor;
use Reactor\Diagnostics\RuntimeMetrics;
use Reactor\RateLimit\MemoryRateLimitStore;
use Reactor\RateLimit\RateLimitStoreInterface;
use Reactor\RateLimit\RedisRateLimitStore;
use Reactor\RateLimit\ThrottleGuard;
use function Amp\Redis\createRedisClient;

/** Default services for bot features; application providers may override them. */
final class BotFeaturesServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        if (!$container->has(CacheInterface::class)) {
            $container->singleton(CacheInterface::class, fn ($c) => new CacheManager([
                'driver' => 'file', 'path' => $c->get(Paths::class)->storage() . '/cache',
            ]));
        }
        $container->singleton(RuntimeMetrics::class, fn () => new RuntimeMetrics());
        $container->singleton(LoopLagMonitor::class, fn ($c) => new LoopLagMonitor(
            $c->get(RuntimeMetrics::class), $c->get(Paths::class)->storage() . '/framework/metrics.json'
        ));
        $container->singleton(UpdateSequencer::class, fn () => new UpdateSequencer());
        $container->singleton(ConversationManager::class, fn ($c) => new ConversationManager(
            $c->get(CacheInterface::class), $this->stateNamespace($c)
        ));
        $container->singleton(CallbackStore::class, fn ($c) => new CallbackStore(
            $c->get(CacheInterface::class), $this->stateNamespace($c)
        ));
        $container->singleton(RateLimitStoreInterface::class, function ($c) {
            $config = $c->get(Config::class);
            return match ($config->get('features.throttle.driver', 'memory')) {
                'memory' => new MemoryRateLimitStore(),
                'redis' => new RedisRateLimitStore(
                    createRedisClient((string) $config->get('features.throttle.redis_uri', 'redis://127.0.0.1:6379')),
                    'reactor:throttle:' . $this->stateNamespace($c) . ':'
                ),
                default => throw new \InvalidArgumentException('Unknown throttle driver.'),
            };
        });
        $container->singleton(ThrottleGuard::class, fn ($c) => new ThrottleGuard($c->get(RateLimitStoreInterface::class)));
    }

    private function stateNamespace(Container $container): string
    {
        $config = $container->get(Config::class);
        return (string) $config->get('features.state_namespace',
            substr(hash('sha256', $config->getToken() ?: $container->get(Paths::class)->base()), 0, 16));
    }

    public function boot(Container $container): void {}
}
