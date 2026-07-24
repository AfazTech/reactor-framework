<?php

namespace Reactor\Core\Routing\Matchers;

use Reactor\Core\HandlerExecution;
use Reactor\Core\Routing\HandlerRegistry;
use Reactor\Contracts\LoggerInterface;

/**
 * Matcher for callback queries (inline keyboard buttons).
 */
class CallbackMatcher
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
        $callbackData = $update['callback_query']['data'] ?? null;
        if ($callbackData === null) {
            return null;
        }

        // 1. Exact callbacks
        foreach ($this->registry->getCallbacks() as $pattern => $config) {
            if ($this->matchesCallbackData($callbackData, $pattern) &&
                $this->allowsUpdateType($config['allowedUpdates'], $updateType)) {
                $this->logger->debug('Callback matched', ['pattern' => $pattern, 'class' => $config['class']]);
                return new HandlerExecution(
                    handlerClass: $config['class'],
                    params: [],
                    metadata: $config['metadata'],
                    update: $update
                );
            }
        }

        // 2. Callback regex patterns
        foreach ($this->registry->getPatterns() as $patternConfig) {
            if ($patternConfig['type'] !== 'callback') {
                continue;
            }
            if (!$this->allowsUpdateType($patternConfig['handler']['allowedUpdates'], $updateType)) {
                continue;
            }
            if (preg_match($patternConfig['pattern'], $callbackData, $matches)) {
                $params = array_slice($matches, 1);
                $this->logger->debug('Callback regex matched', [
                    'pattern' => $patternConfig['pattern'],
                    'class'   => $patternConfig['handler']['class'],
                ]);
                return new HandlerExecution(
                    handlerClass: $patternConfig['handler']['class'],
                    params: $params,
                    metadata: $patternConfig['handler']['metadata'],
                    update: $update
                );
            }
        }

        return null;
    }

    private function matchesCallbackData(string $data, string $pattern): bool
    {
        if ($data === $pattern) {
            return true;
        }
        if (str_starts_with($data, $pattern)) {
            $nextChar = substr($data, strlen($pattern), 1);
            if ($nextChar === '' || $nextChar === ':' || $nextChar === '_' || $nextChar === '|') {
                return true;
            }
        }
        return false;
    }

    private function allowsUpdateType(array $allowed, string $type): bool
    {
        return in_array('any', $allowed, true) || in_array($type, $allowed, true);
    }
}
