<?php

namespace Reactor\Core\Routing\Matchers;

use Reactor\Core\HandlerExecution;
use Reactor\Core\Routing\HandlerRegistry;
use Reactor\Contracts\LoggerInterface;

/**
 * Matcher for Telegram commands (starting with '/').
 */
class CommandMatcher
{
    private HandlerRegistry $registry;
    private LoggerInterface $logger;

    public function __construct(HandlerRegistry $registry, LoggerInterface $logger)
    {
        $this->registry = $registry;
        $this->logger = $logger;
    }

    public function match(array $update, string $updateType): ?HandlerExecution
    {
        $text = trim($update['message']['text'] ?? '');
        if ($text === '' || !str_starts_with($text, '/')) {
            return null;
        }

        $commandName = explode(' ', $text)[0];
        $commands = $this->registry->getCommands();

        if (!isset($commands[$commandName])) {
            return null;
        }

        $config = $commands[$commandName];
        if (!$this->allowsUpdateType($config['allowedUpdates'], $updateType)) {
            return null;
        }

        $this->logger->debug('Command matched', [
            'command' => $commandName,
            'class'   => $config['class'],
            'priority' => $config['priority'],
        ]);

        return new HandlerExecution(
            handlerClass: $config['class'],
            params: [],
            metadata: $config['metadata'],
            update: $update
        );
    }

    private function allowsUpdateType(array $allowed, string $type): bool
    {
        return in_array('any', $allowed, true) || in_array($type, $allowed, true);
    }
}
