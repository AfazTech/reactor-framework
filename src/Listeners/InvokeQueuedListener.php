<?php

namespace Reactor\Listeners;

use Reactor\Contracts\JobInterface;
use Reactor\Contracts\QueuedEventListenerInterface;
use Reactor\Core\Container;

final class InvokeQueuedListener implements JobInterface
{
    public function __construct(private Container $container) {}
    public function handle(array $data): void
    {
        $class = $data['listener'] ?? '';
        if (!is_string($class) || !is_subclass_of($class, QueuedEventListenerInterface::class)
            || !is_string($data['event'] ?? null) || !is_array($data['arguments'] ?? null)) {
            throw new \InvalidArgumentException('Invalid queued listener payload.');
        }
        $listener = $this->container->get($class);
        if (!$listener instanceof QueuedEventListenerInterface) { throw new \LogicException('Invalid listener binding.'); }
        $listener->handle($data['event'], $data['arguments']);
    }
}
