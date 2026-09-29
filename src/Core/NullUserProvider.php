<?php

namespace Reactor\Core;

use Reactor\Contracts\UserProviderInterface;

/**
 * Null implementation of UserProviderInterface.
 *
 * Used as a fallback binding when the host application has not
 * registered a real user provider. This keeps the framework usable
 * in standalone mode (without the application skeleton) without
 * breaking router step lookups or language resolution.
 *
 * Every user reports the default language "en" and no current step.
 * Applications that need persistence should bind their own
 * UserProviderInterface implementation via a service provider.
 */
class NullUserProvider implements UserProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function getLanguage(int $userId): string
    {
        return 'en';
    }

    /**
     * {@inheritdoc}
     */
    public function getStep(int $userId): ?string
    {
        return null;
    }
}
