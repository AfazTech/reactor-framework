<?php

namespace Reactor\Core\Routing;

/**
 * Parses Telegram command syntax.
 *
 * Telegram commands take one of the following forms:
 *
 *   /command
 *   /command arg1 arg2
 *   /command@BotUsername
 *   /command@BotUsername arg1 arg2
 *
 * Whitespace between tokens may be spaces, tabs, or newlines. In group
 * chats Telegram appends "@BotUsername" to disambiguate which bot the
 * command targets; the username portion is not part of the canonical
 * command name and must be stripped before the handler registry lookup.
 *
 * This class is intentionally stateless so it can be reused across the
 * whole application and unit-tested in isolation.
 */
class CommandParser
{
    /**
     * Quick check whether the given text looks like a command.
     *
     * A command always starts with "/". This method performs no further
     * validation; use parse() to obtain a validated structure.
     */
    public function isCommand(string $text): bool
    {
        return $text !== '' && $text[0] === '/';
    }

    /**
     * Parse a command string into its structural parts.
     *
     * @param string $text The raw message text.
     *
     * @return array{
     *     name: string,
     *     raw: string,
     *     botName: ?string,
     *     args: array<int, string>
     * }|null Null when the text is not a valid command.
     */
    public function parse(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || $text[0] !== '/') {
            return null;
        }

        // Split on any whitespace (spaces, tabs, newlines) instead of
        // only single spaces, per the Telegram Bot API command format.
        $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return null;
        }

        $raw = array_shift($parts);
        $args = $parts;

        // Split "/command@BotUsername" into name + bot name.
        $name = $raw;
        $botName = null;
        if (str_contains($raw, '@')) {
            [$name, $botName] = explode('@', $raw, 2);
        }

        // A valid command name starts with "/" and carries at least one
        // character after the slash. A lone "/" is not a valid command.
        if (!str_starts_with($name, '/') || strlen($name) < 2) {
            return null;
        }

        return [
            'name'    => $name,
            'raw'     => $raw,
            'botName' => $botName,
            'args'    => $args,
        ];
    }
}
