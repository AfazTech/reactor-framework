<?php
namespace Reactor\Core;

/**
 * Value object representing the result of handler resolution.
 *
 * Contains the handler class, parameters, metadata, and the original update.
 */
class HandlerExecution
{
    public function __construct(
        public ?string $handlerClass = null,
        public array $params = [],
        public array $metadata = [
            'groups' => [],
            'useMiddlewares' => [],
            'allowedUpdates' => [],
            'priority' => 0,
        ],
        public ?array $update = null
    ) {}

    /**
     * Check whether a handler was found.
     *
     * @return bool
     */
    public function hasHandler(): bool
    {
        return $this->handlerClass !== null;
    }
}
