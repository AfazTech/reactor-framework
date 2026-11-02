<?php

namespace Reactor\Core;

/**
 * Synchronous event dispatcher.
 *
 * Supports wildcard listeners (registered with "*") which receive the
 * event name as their first argument. Any listener can stop further
 * propagation by returning the boolean value `false`.
 */
class EventDispatcher
{
    /**
     * @var array<string, array<int, callable>>  Registered listeners per event.
     */
    private array $listeners = [];

    /**
     * @var array<int, callable>  Wildcard listeners invoked for every event.
     */
    private array $wildcardListeners = [];

    private array $queued = [];

    public function __construct(private ?\Closure $enqueue = null) {}

    public function onQueued(string $event, string $listener, string $queue = 'default'): void
    {
        if ($event === '' || $queue === '') { throw new \InvalidArgumentException('Event and queue cannot be empty.'); }
        if (!is_subclass_of($listener, \Reactor\Contracts\QueuedEventListenerInterface::class)) {
            throw new \InvalidArgumentException('Queued listeners must implement QueuedEventListenerInterface.');
        }
        $this->queued[$event][$listener . ':' . $queue] = [$listener, $queue];
    }

    public function subscribe(string $listener): void
    {
        foreach ((new \ReflectionClass($listener))->getAttributes(\Reactor\Attributes\QueuedListener::class) as $attribute) {
            $binding = $attribute->newInstance();
            $this->onQueued($binding->event, $listener, $binding->queue);
        }
    }

    private function assertPlainData(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $item) { $this->assertPlainData($item); } return; }
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException('Queued event arguments must contain only JSON-compatible data.');
        }
    }

    /**
     * Register a listener for an event. Use "*" to register a wildcard listener.
     */
    public function on(string $event, callable $listener): void
    {
        if ($event === '*') {
            $this->wildcardListeners[] = $listener;
            return;
        }
        $this->listeners[$event][] = $listener;
    }

    /**
     * Unregister a listener.
     */
    public function off(string $event, callable $listener): void
    {
        if ($event === '*') {
            $this->wildcardListeners = array_values(array_filter(
                $this->wildcardListeners,
                fn($l) => $l !== $listener
            ));
            return;
        }

        if (isset($this->listeners[$event])) {
            $this->listeners[$event] = array_values(array_filter(
                $this->listeners[$event],
                fn($l) => $l !== $listener
            ));
        }
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * @return bool True if propagation was stopped by a listener, false otherwise.
     */
    public function dispatch(string $event, ...$args): bool
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            if ($listener(...$args) === false) {
                return true;
            }
        }

        foreach ($this->wildcardListeners as $listener) {
            if ($listener($event, ...$args) === false) {
                return true;
            }
        }

        foreach ($this->queued[$event] ?? [] as [$listener, $queue]) {
            if ($this->enqueue === null) { throw new \LogicException('No queued-event dispatcher configured.'); }
            $json = json_encode($args, JSON_THROW_ON_ERROR);
            $this->assertPlainData($args);
            if (strlen($json) > 65536) { throw new \LengthException('Queued event payload exceeds 64 KiB.'); }
            ($this->enqueue)($listener, $event, $args, $queue);
        }
        return false;
    }
}
