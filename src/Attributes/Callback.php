<?php
namespace Reactor\Attributes;

/**
 * Attribute to mark a class as a callback query handler.
 *
 * This attribute is used to map a handler class to specific callback data
 * or a regex pattern for callback queries from inline keyboards.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Callback
{
    /**
     * @param string      $data     Exact callback data to match.
     * @param bool        $isStep   Whether this callback is step‑aware.
     * @param string|null $pattern  Regex pattern to match callback data.
     * @param int         $priority Priority for matching (higher = earlier).
     */
    public function __construct(
        public string $data,
        public bool $isStep = false,
        public ?string $pattern = null,
        public int $priority = 0
    ) {}
}
