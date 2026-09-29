<?php
namespace Reactor\Attributes;

/**
 * Attribute to specify local middleware for a handler.
 *
 * Middleware listed here will be executed only for this handler,
 * in addition to any global or group middleware.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class UseMiddleware
{
    /**
     * @param array $middlewares List of middleware class names.
     */
    public function __construct(
        public array $middlewares = []
    ) {}
}
