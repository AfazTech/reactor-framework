<?php

namespace Reactor\Contracts;

/**
 * Contract for user data provider used by the core framework.
 *
 * This interface provides only the methods needed by the core,
 * keeping the framework decoupled from application-specific repositories.
 */
interface UserProviderInterface
{
    /**
     * Get the user's preferred language code.
     *
     * @param int $userId Telegram user ID.
     * @return string Language code (e.g., 'en', 'fa').
     */
    public function getLanguage(int $userId): string;

    /**
     * Get the current step of the user.
     *
     * @param int $userId Telegram user ID.
     * @return string|null Step name or null if not set.
     */
    public function getStep(int $userId): ?string;
}
