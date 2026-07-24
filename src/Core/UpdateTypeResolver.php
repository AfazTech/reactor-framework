<?php

namespace Reactor\Core;

use Reactor\Constants\UpdateTypes;

/**
 * Resolves the type of a Telegram update using the central list.
 */
class UpdateTypeResolver
{
    /**
     * Determines the update type from the given update array.
     *
     * @param array $update The incoming update from Telegram.
     *
     * @return string The update type, or 'unknown' if not recognised.
     */
    public function getType(array $update): string
    {
        foreach (UpdateTypes::getAll() as $type) {
            if (isset($update[$type])) {
                return $type;
            }
        }

        return 'unknown';
    }
}
