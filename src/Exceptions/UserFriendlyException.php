<?php

namespace Reactor\Exceptions;

/**
 * Exception that should be shown to the user with a friendly message.
 *
 * The user-facing text is resolved from the language files using the
 * translation key returned by getUserMessageKey(). The technical message
 * (if any) is only written to the application log.
 */
class UserFriendlyException extends ReactorException
{
    private string $userMessageKey;

    public function __construct(
        string $userMessageKey,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->userMessageKey = $userMessageKey;
    }

    public function getUserMessageKey(): string
    {
        return $this->userMessageKey;
    }
}
