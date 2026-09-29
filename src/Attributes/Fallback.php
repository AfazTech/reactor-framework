<?php
namespace Reactor\Attributes;

/**
 * Attribute to designate a class as the fallback handler.
 *
 * The fallback handler is invoked when no other handler matches the update.
 * Priority determines which fallback is used if multiple are defined.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Fallback
{
    /**
     * @param int $priority Priority of this fallback (higher = earlier).
     */
    public function __construct(
        public int $priority = 0
    ) {}
}
