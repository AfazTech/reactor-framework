<?php
namespace Reactor\Attributes;

/**
 * Attribute to assign a handler to one or more middleware groups.
 *
 * Group middleware will be executed before the handler if the group matches.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Group
{
    /**
     * @param string|array $groups One or more group names.
     */
    public function __construct(
        public string|array $groups
    ) {}
}
