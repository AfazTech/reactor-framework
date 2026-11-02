<?php

namespace Reactor\Core;

/** Fiber-local, nested update scopes; no cache is retained between updates. */
final class UpdateScope
{
    private \WeakMap $frames;
    private object $main;

    public function __construct()
    {
        $this->frames = new \WeakMap();
        $this->main = new \stdClass();
    }

    public function run(callable $callback): mixed
    {
        $key = \Fiber::getCurrent() ?? $this->main;
        $stack = $this->frames[$key] ?? [];
        $frame = new \stdClass();
        $frame->values = [];
        $stack[] = $frame;
        $this->frames[$key] = $stack;
        try {
            return $callback();
        } finally {
            $stack = $this->frames[$key];
            array_pop($stack);
            if ($stack === []) {
                unset($this->frames[$key]);
            } else {
                $this->frames[$key] = $stack;
            }
        }
    }

    public function remember(string $key, callable $read): mixed
    {
        $frame = $this->current();
        if ($frame === null) {
            return $read();
        }
        if (!array_key_exists($key, $frame->values)) {
            $frame->values[$key] = $read();
        }
        return $frame->values[$key];
    }

    public function forget(string $key): void
    {
        $frame = $this->current();
        if ($frame !== null) {
            unset($frame->values[$key]);
        }
    }

    private function current(): ?object
    {
        $stack = $this->frames[\Fiber::getCurrent() ?? $this->main] ?? [];
        return $stack === [] ? null : $stack[array_key_last($stack)];
    }
}
