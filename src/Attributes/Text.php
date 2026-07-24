<?php
namespace Reactor\Attributes;

/**
 * Attribute to map a handler class to text messages or commands.
 *
 * The handler will be invoked when the user sends a message that matches
 * the given text key (translated) or a regex pattern.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Text
{
    /**
     * @param string      $name      Text key or command (e.g., 'about', '/start').
     * @param bool        $isCommand Whether this is a command (starts with '/').
     * @param int         $priority  Matching priority (higher = earlier).
     * @param string|null $pattern   Regex pattern for advanced matching.
     */
    public function __construct(
        public string $name = '',
        public bool $isCommand = false,
        public int $priority = 0,
        public ?string $pattern = null
    ) {}
}
