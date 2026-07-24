<?php
namespace Reactor\Attributes;

use Reactor\Enums\MiddlewareMode;

/**
 * Attribute to define middleware properties.
 *
 * This attribute is placed on a middleware class to set its priority,
 * execution mode, and the groups it belongs to.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Middleware
{
    /**
     * @param int           $priority Execution priority (higher = earlier).
     * @param MiddlewareMode $mode     Scope: GLOBAL, GROUP, or LOCAL.
     * @param array         $groups   Group names if mode is GROUP.
     */
    public function __construct(
        public int $priority = 0,
        public MiddlewareMode $mode = MiddlewareMode::GLOBAL,
        public array $groups = []
    ) {}
}
