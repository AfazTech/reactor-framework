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

        return false;
    }
}
